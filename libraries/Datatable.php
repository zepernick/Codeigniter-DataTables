<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 Paul Zepernick
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 */

/**
 * Codeigniter Datatable library
 *
 *
 * @author Paul Zepernick
 */
class Datatable
{

    private static $VALID_MATCH_TYPES = array('before', 'after', 'both', 'none');

    private $model;

    private $CI;

    private $rowIdCol;

    private $preResultFunc = FALSE;

    // assoc. array.  key is column name being passed from the DataTables data property and value is before, after, both, none
    private $matchType = array();

    private $protectIdentifiers = FALSE;


    /**
     * @params
     *        Associative array.  Expecting key "model" and the value name of the model to load
     */
    public function __construct($params)
    {
        $CI =& get_instance();

        if (isset($params['model']) === FALSE) {
            throw new Exception('Expected a parameter named "model".');
        }

        $model = $params['model'];

        $this->rowIdCol = isset($params['rowIdCol']) ? $params['rowIdCol'] : NULL;

        $CI->load->model($model);

        if (($CI->$model instanceof DatatableModel) === false) {
            throw new Exception('Model must implement the DatatableModel Interface');
        }

        //even though $model is a String php looks at the String value and finds the property
        //by that name.  Hence the $ when that would not normally be there for a property
        $this->model = $CI->$model;
        $this->CI = $CI;

    }


    /**
     * Turn on/off protect identifiers from the Query Builder
     *
     * @param $boolProtect should database identifiers be protected?
     * @return $this
     */
    public function setProtectIdentifiers($boolProtect)
    {
        $this->protectIdentifiers = $boolProtect;
        return $this;
    }

    /**
     * Register a function that will fire after the JSON object is put together
     * in the library, but before sending it to the browser.  The function should accept 1 parameter
     * for the JSON object which is stored as associated array.
     *
     * IMPORTANT: Make sure to add a & in front of the parameter to get a reference of the Array,otherwise
     * your changes will not be picked up by the library
     *
     *        function(&$json) {
     *            //do some work and add to the json if you wish.
     *        }
     */
    public function setPreResultCallback($func)
    {
        if (is_object($func) === FALSE || ($func instanceof Closure) === FALSE) {
            throw new Exception('Expected Anonymous Function Parameter Not Received');
        }

        $this->preResultFunc = $func;

        return $this;
    }


    /**
     * Sets the wildcard matching to be a done on a specific column in the search
     *
     * @param col
     *        column sepcified in the DataTables "data" property
     * @param type
     *        Type of wildcard search before, after, both, none.  Default is after if not specified for a column.
     * @return    Datatable
     */
    public function setColumnSearchType($col, $type)
    {
        $type = trim(strtolower($type));
        //make sure we have a valid type
        if (in_array($type, self:: $VALID_MATCH_TYPES) === FALSE) {
            throw new Exception('[' . $type . '] is not a valid type.  Must Use: ' . implode(', ', self:: $VALID_MATCH_TYPES));
        }

        $this->matchType[$col] = $type;

        //	log_message('info', 'setColumnSearchType() ' . var_export($this -> matchType, TRUE));

        return $this;
    }

    /**
     * Get the current search type for a column
     *
     * @param col
     *        column sepcified in the DataTables "data" property
     *
     * @return search type string
     */
    public function getColumnSearchType($col)
    {
        //	log_message('info', 'getColumnSearchType() ' . var_export($this -> matchType, TRUE));
        return isset($this->matchType[$col]) ? $this->matchType[$col] : 'after';
    }

    /**
     * @param formats
     *            Associative array.
     *                Key is column name
     *                Value format: percent, currency, date, boolean
     */
    public function datatableJson($formats = array(), $debug = FALSE)
    {

        $f = $this->CI->input;
        $start = (int)$f->post_get('start');
        $limit = (int)$f->post_get('length');


        $jsonArry = array();
        $jsonArry['start'] = $start;
        $jsonArry['limit'] = $limit;
        $jsonArry['draw'] = (int)$f->post_get('draw');
        $jsonArry['recordsTotal'] = 0;
        $jsonArry['recordsFiltered'] = 0;
        $jsonArry['data'] = array();

        //query the data for the records being returned
        $selectArray = array();
        $customCols = array();
        $columnIdxArray = array();

        foreach ($f->post_get('columns') as $c) {
            $columnIdxArray[] = $c['data'];
            if (substr($c['data'], 0, 1) === '$') {
                //indicates a column specified in the appendToSelectStr()
                $customCols[] = $c['data'];
                continue;
            }
            $selectArray[] = $c['data'];
        }
        if ($this->rowIdCol !== NULL && in_array($this->rowIdCol, $selectArray) === FALSE) {
            $selectArray[] = $this->rowIdCol;
        }

        //put the select string together
        $sqlSelectStr = implode(', ', $selectArray);
        $appendStr = $this->model->appendToSelectStr();
        if (is_null($appendStr) === FALSE) {
            foreach ($appendStr as $alias => $sqlExp) {
                $sqlSelectStr .= ', ' . $sqlExp . ' ' . $alias;
            }

        }


        //setup order by
        $customExpArray = is_null($this->model->appendToSelectStr()) ?
            array() :
            $this->model->appendToSelectStr();
        foreach ($f->post_get('order') as $o) {
            if ($o['column'] !== '') {
                $colName = $columnIdxArray[$o['column']];
                //handle custom sql expressions/subselects
                if (substr($colName, 0, 2) === '$.') {
                    $aliasKey = substr($colName, 2);
                    if (isset($customExpArray[$aliasKey]) === FALSE) {
                        throw new Exception('Alias[' . $aliasKey . '] Could Not Be Found In appendToSelectStr() Array');
                    }

                    $colName = $customExpArray[$aliasKey];
                }
                $this->CI->db->order_by($colName, $o['dir']);
            }
        }

        //echo $sqlSelectStr;

        $this->CI->db->select($sqlSelectStr, $this->protectIdentifiers);
        $whereDebug = $this->sqlJoinsAndWhere();
        $this->CI->db->limit($limit, $start);
        $query = $this->CI->db->get();

        $jsonArry = array();

        if (!$query) {
            $jsonArry['errorMessage'] = $this->CI->db->_error_message();
            return $jsonArry;
        }

        if ($debug === TRUE) {
            $jsonArry['debug_sql'] = $this->CI->db->last_query();
        }

        //process the results and create the JSON objects
        $dataArray = array();
        $allColsArray = array_merge($selectArray, $customCols);
        foreach ($query->result() as $row) {
            $colObj = array();
            //loop rows returned by the query
            foreach ($allColsArray as $c) {
                if (trim($c) === '') {
                    continue;
                }

                $propParts = explode('.', $c);

                $prop = trim(end($propParts));
                //loop columns in each row that the grid has requested
                if (count($propParts) > 1) {
                    //nest the objects correctly in the json if the column name includes
                    //the table alias
                    $nestedObj = array();
                    if (isset($colObj[$propParts[0]])) {
                        //check if we alraedy have a object for this alias in the array
                        $nestedObj = $colObj[$propParts[0]];
                    }


                    $nestedObj[$propParts[1]] = $this->formatValue($formats, $prop, $row->$prop);
                    $colObj[$propParts[0]] = $nestedObj;
                } else {
                    $colObj[$c] = $this->formatValue($formats, $prop, $row->$prop);
                }
            }

            if ($this->rowIdCol !== NULL) {
                $tmpRowIdSegments = explode('.', $this->rowIdCol);
                $idCol = trim(end($tmpRowIdSegments));
                $colObj['DT_RowId'] = $row->$idCol;
            }
            $dataArray[] = $colObj;
        }


        $this->sqlJoinsAndWhere();
        $totalRecords = $this->CI->db->count_all_results();


        $jsonArry['start'] = $start;
        $jsonArry['limit'] = $limit;
        $jsonArry['draw'] = (int)$f->post_get('draw');
        $jsonArry['recordsTotal'] = $totalRecords;
        $jsonArry['recordsFiltered'] = $totalRecords;
        $jsonArry['data'] = $dataArray;
        //$jsonArry['debug'] = $whereDebug;

        if ($this->preResultFunc !== FALSE) {
            $func = $this->preResultFunc;
            $func($jsonArry);
        }

        return $jsonArry;

    }

    private function formatValue($formats, $column, $value)
    {
        if (isset($formats[$column]) === FALSE || trim($value) == '') {
            return $value;
        }

        switch ($formats[$column]) {
            case 'date' :
                $dtFormats = array('Y-m-d H:i:s', 'Y-m-d');
                $dt = null;
                //try to parse the date as 2 different formats
                foreach ($dtFormats as $f) {
                    $dt = DateTime::createFromFormat($f, $value);
                    if ($dt !== FALSE) {
                        break;
                    }
                }
                if ($dt === FALSE) {
                    //neither pattern could parse the date
                    throw new Exception('Could Not Parse To Date For Formatting [' . $value . ']');
                }
                return $dt->format('m/d/Y');
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
    private function sqlJoinsAndWhere()
    {
        $debug = '';
        // this is protected in CI 3 and can no longer be turned off. must be turned off in the config
        // $this -> CI -> db-> _protect_identifiers = FALSE;
        $this->CI->db->from($this->model->fromTableStr());

        $joins = $this->model->joinArray() === NULL ? array() : $this->model->joinArray();
        foreach ($joins as $table => $on) {
            $joinTypeArray = explode('|', $table);
            $tableName = $joinTypeArray[0];
            $join = 'inner';
            if (count($joinTypeArray) > 1) {
                $join = $joinTypeArray[1];
            }
            $this->CI->db->join($tableName, $on, $join, $this->protectIdentifiers);
        }

        $customExpArray = is_null($this->model->appendToSelectStr()) ?
            array() :
            $this->model->appendToSelectStr();

        $f = $this->CI->input;

        $searchableColumns = array();
        foreach ($f->post_get('columns') as $c) {

            $colName = $c['data'];

            if (substr($colName, 0, 2) === '$.') {
                $aliasKey = substr($colName, 2);
                if (isset($customExpArray[$aliasKey]) === FALSE) {
                    throw new Exception('Alias[' . $aliasKey . '] Could Not Be Found In appendToSelectStr() Array');
                }

                $colName = $customExpArray[$aliasKey];
            }

            if ($c['searchable'] !== 'false') {
                $searchableColumns[] = $colName;
            }

            if ($c['search']['value'] !== '') {
                $searchType = $this->getColumnSearchType($colName);
                //log_message('info', 'colname[' . $colName . '] searchtype[' . $searchType . ']');
                //handle custom sql expressions/subselects

                $debug .= 'col[' . $c['data'] . '] value[' . $c['search']['value'] . '] ' . PHP_EOL;
                //	log_message('info', 'colname[' . $colName . '] searchtype[' . $searchType . ']');
                $this->CI->db->like($colName, $c['search']['value'], $searchType, $this->protectIdentifiers);
            }
        }


        // put together a global search if specified
        $globSearch = $f->post_get('search');
        if ($globSearch['value'] !== '') {
            $gSearchVal = $globSearch['value'];
            $sqlOr = '';
            $op = '';
            foreach ($searchableColumns as $c) {
                $sqlOr .= $op . $c . ' LIKE \'' . $this->CI->db->escape_like_str($gSearchVal) . '%\'';
                $op = ' OR ';
            }

            $this->CI->db->where('(' . $sqlOr . ')');
        }


        //append a static where clause to what the user has filtered, if the model tells us to do so
        $wArray = $this->model->whereClauseArray();
        if (is_null($wArray) === FALSE && is_array($wArray) === TRUE && count($wArray) > 0) {
            $this->CI->db->where($wArray, $this->protectIdentifiers);
        }

        return $debug;
    }


}

interface DatatableModel
{

    /**
     * @ return
     *        Expressions / Columns to append to the select created by the Datatable library.
     *        Associative array where the key is the sql alias and the value is the sql expression
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
     * @return
     *    Static where clause to be appended to all search queries.  Return NULL or empty array
     * when not filtering by additional criteria
     */
    public function whereClauseArray();
}

// END Datatable Class
/* End of file Datatable.php */