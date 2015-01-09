#DataTable Library for Codeigniter

This library requires **DataTables 1.10 >**


* Generates Necessary JSON For Sever Side Tables

* Handles Sorting & Column Based Searching

* Builds the select automatically from the DataTable column names

* Append custom SQL expressions to the generated Select statement

* Append a static where clause

* Formatters For Date, Percent, Currency, and Boolean

* Utilizes CI Active Record 

* Drastically Reduces PHP Code Necessary To Generate a Server Side Table

Install
-----

* *jQuery* 

	`<script type="text/javascript" language="javascript" src="//code.jquery.com/jquery-1.11.1.min.js"></script>`

* *DataTables* 
	
    `<script type="text/javascript" language="javascript" src="//cdn.datatables.net/1.10.4/js/jquery.dataTables.min.js"></script>`
    
    `<link rel="stylesheet" type="text/css" href="//cdn.datatables.net/1.10.4/css/jquery.dataTables.css">`
    
* *DataTable Library* 

	Copy Datatable.php to the libraries folder of your CI install

Parameters
-----

Name          | Description   | Required
------------- | ------------- | ---------
model  | Model classname implmenting the DatatableModel interface  | **YES**
rowIdCol  | Column name used to assign the row id values in the table  | NO


DatatableModel Interface
-----
Name          | Description   | Return
------------- | ------------- | ---------
`appendToSelectStr` | Adds additional columns or SQL Expressions to the generated SELECT | **Assoc. Array** *Key* = Column Alias, *Value* = Column Name or Expression **OR** *NULL*
`fromTableStr` | Specify the table name to select from. *Tip: You can also include an alias here `return 'mytable a';`*| **String **
`joinArray` | Join additional tables for DataTable columns to reference.  *Tip: Join Types CAN be specifed by using a pipe in the key value `'table_to_join b&#124;left outer'`*| **Assoc. Array** *Key*=Table To Join *Value*=SQL Join Expression.
`whereClauseArray`| Append Static SQL to the generated Where Clause| **Assoc. Array** *Key*= Column Name *Value*=Value To Filter **OR** *NULL*





Basic DatatableModel Implementation
--------
```php
    class store_dt extends MY_Model implements DatatableModel{
    	
		public function appendToSelectStr() {
				return NULL;
		}
    	
		public function fromTableStr() {
			return 'store s';
		}
    
	    public function joinArray(){
	    	return NULL;
	    }
	    
    	public function whereClauseArray(){
    		return NULL;
    	}
   }
```

More Advanced DatatableModel Implementation
--------
```php
    class state_dt extends MY_Model implements DatatableModel{
			

		public function appendToSelectStr() {
				return array(
					'city_state_zip' => 'concat(s.s_name, \'  \', c.c_name, \'  \', c.c_zip)'
				);
				
		}
    	
		public function fromTableStr() {
			return 'state s';
		}
		
    

	    public function joinArray(){
	    	return array(
	    	  'city c|left outer' => 'c.state_id = s.id',
              'user u' => 'u.state_id = s.id'
              );
	    }
	    
    	public function whereClauseArray(){
			return array(
				'u.id' => $this -> ion_auth -> get_user_id() 
				);
    	}
   }
```

Controller Example
-----
```php
class DataTableExample extends CI_Controller {
	public function dataTable() {
    	//Important to NOT load the model and let the library load it instead.  
		$this -> load -> library('Datatable', array('model' => 'state_dt', 'rowIdCol' => 'c.id'));
        
        //format array is optional, but shown here for the sake of example
        $json = $this -> datatable -> datatableJson(
			array(
				'a_date_col' => 'date',
				'a_boolean_col' => 'boolean',
				'a_percent_col' => 'percent',
				'a_currency_col' => 'currency'
			)
		);
        
        $this -> output -> set_header("Pragma: no-cache");
        $this -> output -> set_header("Cache-Control: no-store, no-cache");
        $this -> output -> set_content_type('application/json') -> set_output(json_encode($json));
 
    }

}
```

Table HTML In View
-----
```html
<table id="myDataTable">
	<thead>
    	<tr>
        	<th>State</th>
            <th>City</th>
            <th>Zip</th>
            <th>Combined Exp</th>
        </tr>
    </thead>
    <tbody></tbody>
</table>
```

JavaScript To Call Controller
-----
**Custom Expressions: ** SQL Expressions setup in the model in the `appendToSelectStr()` must be reference by the `$` 
in the `data` property of the `columns` array.

```javascript
$('#myDataTable').dataTable( {
        processing: true,
        serverSide: true,
        ajax: '/index.php/DataTableExample/dataTable',
        columns: [
            { data: "s.s_name" },
            {data : "c.c_name"},
            {data : "c.c_zip"},
            { data: "$.city_state_zip" } //refers to the expression in the "More Advanced DatatableModel Implementation"
        ]
    });
```

Resources
-----
* <a href="http://datatables.net/">DataTables</a>

* <a href="https://datatables.net/reference/api/column%28%29.search%28%29">DataTable Column Search Documentation</a>

License Information
-------------------

License: The MIT License (MIT), http://opensource.org/licenses/MIT