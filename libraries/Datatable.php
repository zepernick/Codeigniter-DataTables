<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/** 
 * The MIT License (MIT)

Copyright (c) 2015 Paul Zepernick

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
 * 
 */
 
/**
 * Codeigniter Datatable library
 * 
 *
 * @author Paul Zepernick
 */
class Datatable{
	

    var $model;
	
	var $CI;
	
	var $rowIdCol;
    
    /**
	 * @params
	 * 		Associative array.  Expecting key "model" and the value name of the model to load
	 */
	public function __construct($params)	{
		$CI =& get_instance();
		
		if(isset($params['model']) === FALSE) {
			 throw new Exception('Expected a parameter named "model".');
		}
		
		$model = $params['model'];
		
		$this -> rowIdCol = isset($params['rowIdCol']) ? $params['rowIdCol'] : NULL;
        
		$CI -> load -> model($model);
		
        if(($CI -> $model instanceof DatatableModel) === false) {
            throw new Exception('Model must implement the DatatableModel Interface');
        }
        
        //even though $model is a String php looks at the String value and finds the property
        //by that name.  Hence the $ when that would not normally be there for a property
        $this -> model = $CI -> $model;
		$this -> CI = $CI;
		
	}
	
	/**
	 * @param formats
	 * 			Associative array. 
	 * 				Key is column name
	 * 				Value format: percent, currency, date, boolean
	 */
	public function datatableJson($formats = array()) {
		
		$f = $this -> CI -> input;
		$start = (int)$f -> post('start');
		$limit = (int)$f -> post('length');
		
		
		
		$jsonArry = array();
		$jsonArry['start'] = $start;
		$jsonArry['limit'] = $limit;
		$jsonArry['draw'] = (int)$f -> post('draw');
		$jsonArry['recordsTotal'] = 0;
		$jsonArry['recordsFiltered'] = 0;
		$jsonArry['data'] = array();
		
		//query the data for the records being returned
		$selectArray = array();
		$customCols = array();
		$columnIdxArray = array();
		
		foreach($f -> post('columns') as $c) {
			$columnIdxArray[] = $c['data'];
			if(substr($c['data'], 0, 1) === '$') {
				//indicates a column specified in the appendToSelectStr()
				$customCols[] = $c['data'];
				continue;
			}
			$selectArray[] = $c['data'];
		}
		if($this -> rowIdCol !== NULL && in_array($this -> rowIdCol, $selectArray) === FALSE) {
			$selectArray[] = $this -> rowIdCol; 
		}
		
		//put the select string together
		$sqlSelectStr = implode(', ', $selectArray);
		$appendStr = $this -> model -> appendToSelectStr();
		if (is_null($appendStr) === FALSE) {
			foreach($appendStr as $alias => $sqlExp) {
				$sqlSelectStr .= ', ' . $sqlExp . ' ' . $alias;	
			}
			
		}
		
		
		//setup order by
		$customExpArray = is_null($this -> model -> appendToSelectStr()) ? 
								array() : 
								$this -> model -> appendToSelectStr();
		foreach($f -> post('order') as $o) {
			if($o['column'] !== '') {
				$colName = $columnIdxArray[$o['column']];
				//handle custom sql expressions/subselects
				if(substr($colName, 0, 2) === '$.') {
					$aliasKey = substr($colName, 2);
					if(isset($customExpArray[$aliasKey]) === FALSE) {
						throw new Exception('Alias['. $aliasKey .'] Could Not Be Found In appendToSelectStr() Array');
					}
					
					$colName = $customExpArray[$aliasKey];
				}
				$this -> CI -> db -> order_by($colName, $o['dir']);
			}
		}
		
		//echo $sqlSelectStr;
		
		$this -> CI -> db -> select($sqlSelectStr);			
		$whereDebug = $this -> sqlJoinsAndWhere();
		$this -> CI -> db -> limit($limit, $start);
		$query = $this -> CI -> db -> get();	
		
		if(!$query) {
			$jsonArry['errorMessage'] = $this -> CI -> db -> _error_message();
			return $jsonArry;
		}
		
		
		//process the results and create the JSON objects
		$dataArray = array();
		$allColsArray = array_merge($selectArray, $customCols);
		foreach ($query -> result() as $row) {
			$colObj = array();
			//loop rows returned by the query
			foreach($allColsArray as $c) {
			    if(trim($c) === '') {
			        continue;
			    }
                
				$propParts = explode('.', $c);
				
				$prop = trim(end($propParts)); 
				//loop columns in each row that the grid has requested
				if(count($propParts) > 1) {
					//nest the objects correctly in the json if the column name includes
					//the table alias
					$nestedObj = array();
					if(isset($colObj[$propParts[0]])) {
						//check if we alraedy have a object for this alias in the array
						$nestedObj = $colObj[$propParts[0]];
					}
					
					
					
					$nestedObj[$propParts[1]] = $this -> formatValue($formats, $prop, $row -> $prop);
					$colObj[$propParts[0]] = $nestedObj;
				} else {
					$colObj[$c] = $this -> formatValue($formats, $prop, $row -> $prop);
				}
			}
			
			if($this -> rowIdCol !== NULL) {
				$tmpRowIdSegments = explode('.', $this -> rowIdCol);
				$idCol = trim(end($tmpRowIdSegments));
				$colObj['DT_RowId'] = $row -> $idCol;
			}
			$dataArray[] = $colObj;
		}
		
		
		
		
		$this -> sqlJoinsAndWhere();
		$totalRecords = $this -> CI -> db -> count_all_results();
		
		
		
		$jsonArry = array();
		$jsonArry['start'] = $start;
		$jsonArry['limit'] = $limit;
		$jsonArry['draw'] = (int)$f -> post('draw');
		$jsonArry['recordsTotal'] = $totalRecords;
		$jsonArry['recordsFiltered'] = $totalRecords;
		$jsonArry['data'] = $dataArray;
		$jsonArry['debug'] = $whereDebug;
		
		return $jsonArry;
		
	}

	private function formatValue($formats, $column, $value) {
		if (isset($formats[$column]) === FALSE) {
			return $value;
		}
		
		switch ($formats[$column]) {
			case 'date' :
				$dtFormats = array('Y-m-d H:i:s', 'Y-m-d');
				$dt = null;
				//try to parse the date as 2 different formats
				foreach($dtFormats as $f) {
					$dt = DateTime::createFromFormat($f, $value);
					if($dt !== FALSE) {
						break;
					}
				}
				if($dt === FALSE) {
					//neither pattern could parse the date
					throw new Exception('Could Not Parse To Date For Formatting [' . $value . ']');
				}
				return $dt -> format('m/d/Y');
			case 'percent' :
				///$formatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
				//return $formatter -> format(floatval($value) * .01);
				return $value . '%';
			case 'currency' :
				return '$' . number_format(floatval($value), 2);
			case 'boolean' :
				$b = filter_var($value, FILTER_VALIDATE_BOOLEAN);
				return $b ? 'Yes' : 'No';
		}
		
		return $value;
	}

	
	
	//specify the joins and where clause for the Active Record. This code is common to
	//fetch the data and get a total record count
	private function sqlJoinsAndWhere() {
		$debug = '';
		$this -> CI -> db -> from($this -> model -> fromTableStr());
		
		$joins = $this -> model -> joinArray() === NULL ? array() : $this -> model -> joinArray();
		foreach ($joins as $table => $on) {
			$joinTypeArray = explode('|', $table);
			$tableName = $joinTypeArray[0];
			$join = 'inner';
			if(count($joinTypeArray) > 1) {
				$join = $joinTypeArray[1];
			}
			$this -> CI -> db -> join($tableName, $on, $join);
		}
		
		$customExpArray = is_null($this -> model -> appendToSelectStr()) ? 
								array() : 
								$this -> model -> appendToSelectStr();
		
		$f = $this -> CI -> input;
		foreach($f -> post('columns') as $c) {
			if($c['search']['value'] !== '') {
				$colName = $c['data'];
				//handle custom sql expressions/subselects
				if(substr($colName, 0, 2) === '$.') {
					$aliasKey = substr($colName, 2);
					if(isset($customExpArray[$aliasKey]) === FALSE) {
						throw new Exception('Alias['. $aliasKey .'] Could Not Be Found In appendToSelectStr() Array');
					}
					
					$colName = $customExpArray[$aliasKey];
				}
				$debug .= 'col[' . $c['data'] .'] value[' . $c['search']['value'] . '] ' . PHP_EOL;
				$this -> CI -> db -> like($colName, $c['search']['value'], 'after');
			}
		}
		
        //append a static where clause to what the user has filtered, if the model tells us to do so
		$wArray = $this -> model -> whereClauseArray();
		if(is_null($wArray) === FALSE && is_array($wArray) === TRUE && count($wArray) > 0) {
			$this -> CI -> db -> where($wArray);
		}
		
		return $debug;
	}
	
	
}

interface DatatableModel {
	
	/**
	 * @ return
	 * 		Expressions / Columns to append to the select created by the Datatable library.
	 * 		Associative array where the key is the sql alias and the value is the sql expression
	 */
	public function appendToSelectStr();
	
    /**
     * @return
     *      String table name to select from
     */
    public function fromTableStr();
    
    /**
     * @return
     *     Associative array of joins.  Return NULL or empty array  when not joining
     */
    public function joinArray();
    
	/**
	 * 
	 *@return
	 * 	Static where clause to be appended to all search queries.  Return NULL or empty array
	 * when not filtering by additional criteria
	 */
    public function whereClauseArray();
}

// END Datatable Class
/* End of file Datatable.php */