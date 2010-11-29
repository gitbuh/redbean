<?php
/**
 * RedBean MySQLWriter
 * @package 		RedBean/QueryWriter/PostgreSQL.php
 * @description		Represents a PostgreSQL Database to RedBean
 *			To write a driver for a different database for RedBean
 *			you should only have to change this file.
 * @author			Gabor de Mooij
 * @license			BSD
 */
class RedBean_QueryWriter_PostgreSQL extends RedBean_AQueryWriter implements RedBean_QueryWriter {



	/**
	 * DATA TYPE
	 * Integer Data Type
	 * @var integer
	 */
	const C_DATATYPE_INTEGER = 0;

	/**
	 * DATA TYPE
	 * Double Precision Type
	 * @var integer
	 */
	const C_DATATYPE_DOUBLE = 1;

	/**
	 * DATA TYPE
	 * String Data Type
	 * @var integer
	 */
	const C_DATATYPE_TEXT = 3;





	/**
	 * @var array
	 * Supported Column Types
	 */
	public $typeno_sqltype = array(
			  self::C_DATATYPE_INTEGER=>" integer ",
			  self::C_DATATYPE_DOUBLE=>" double precision ",
			  self::C_DATATYPE_TEXT=>" text "
	);

	/**
	 *
	 * @var array
	 * Supported Column Types and their
	 * constants (magic numbers)
	 */
	public $sqltype_typeno = array(
			  "integer"=>self::C_DATATYPE_INTEGER,
			  "double precision" => self::C_DATATYPE_DOUBLE,
			  "text"=>self::C_DATATYPE_TEXT
	);
	
	/**
	 * @var string
	 * character to escape keyword table/column names
	 */
  protected $quoteCharacter = '"';

  protected $defaultValue = 'DEFAULT';
	 
  protected function getInsertSuffix($table) {
    return "RETURNING ".$this->getIDField($table);
  }  

	/**
	 * Constructor
	 * The Query Writer Constructor also sets up the database
	 * @param RedBean_DBAdapter $adapter
	 */
	public function __construct( RedBean_Adapter_DBAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * Returns all tables in the database
	 * @return array $tables
	 */
	public function getTables() {
		return $this->adapter->getCol( "select table_name from information_schema.tables
where table_schema = 'public'" );
	}

	/**
	 * Creates an empty, column-less table for a bean.
	 * @param string $table
	 */
	public function createTable( $table ) {
		$idfield = $this->getIDfield($table);
		$table = $this->safeTable($table);
		$sql = " CREATE TABLE $table ($idfield SERIAL PRIMARY KEY); ";
		$this->adapter->exec( $sql );
	}

	/**
	 * Returns an array containing the column names of the specified table.
	 * @param string $table
	 * @return array $columns
	 */
	public function getColumns( $table ) {
		$table = $this->safeTable($table, true);
		$columnsRaw = $this->adapter->get("select column_name, data_type from information_schema.columns where table_name='$table'");
		foreach($columnsRaw as $r) {
			$columns[$r["column_name"]]=$r["data_type"];
		}
		return $columns;
	}

	/**
	 * Returns the MySQL Column Type Code (integer) that corresponds
	 * to the given value type.
	 * @param string $value
	 * @return integer $type
	 */
	public function scanType( $value ) {
		if (is_integer($value) && $value < 2147483648 && $value > -2147483648) {
			return self::C_DATATYPE_INTEGER;
		}
		elseif( is_double($value) ) {
			return self::C_DATATYPE_DOUBLE;
		}
		else {
			return self::C_DATATYPE_TEXT;
		}
	}

	/**
	 * Returns the Type Code for a Column Description
	 * @param string $typedescription
	 * @return integer $typecode
	 */
	public function code( $typedescription ) {
		return ((isset($this->sqltype_typeno[$typedescription])) ? $this->sqltype_typeno[$typedescription] : 99);
	}

	/**
	 * Change (Widen) the column to the give type.
	 * @param string $table
	 * @param string $column
	 * @param integer $type
	 */
	public function widenColumn( $table, $column, $type ) {
		$table = $this->safeTable($table);
		$column = $this->safeColumn($column);
		$newtype = $this->typeno_sqltype[$type];
		$changecolumnSQL = "ALTER TABLE $table \n\t ALTER COLUMN $column TYPE $newtype ";
		try {
			$this->adapter->exec( $changecolumnSQL );
		}catch(Exception $e) {
			die($e->getMessage());
		}
	}

	/**
	 * Gets information about changed records using a type and id and a logid.
	 * RedBean Locking shields you from race conditions by comparing the latest
	 * cached insert id with a the highest insert id associated with a write action
	 * on the same table. If there is any id between these two the record has
	 * been changed and RedBean will throw an exception. This function checks for changes.
	 * If changes have occurred it will throw an exception. If no changes have occurred
	 * it will insert a new change record and return the new change id.
	 * This method locks the log table exclusively.
	 * @param  string $type
	 * @param  integer $id
	 * @param  integer $logid
	 * @return integer $newchangeid
	 */
	public function checkChanges($type, $id, $logid) {

		$table = $this->safeTable($type);
		$idfield = $this->getIDfield($type);
		$id = (int) $id;
		$logid = (int) $logid;
		$num = $this->adapter->getCell("
        SELECT count(*) FROM __log WHERE tbl=$table AND itemid=$id AND action=2 AND $idfield > $logid");
		if ($num) {
			throw new RedBean_Exception_FailedAccessBean("Locked, failed to access (type:$type, id:$id)");
		}
		$newid = $this->insertRecord("__log",array("action","tbl","itemid"),
				  array(array(2,  $type, $id)));
		if ($this->adapter->getCell("select id from __log where tbl=:tbl AND id < $newid and id > $logid and action=2 and itemid=$id ",
		array(":tbl"=>$type))) {
			throw new RedBean_Exception_FailedAccessBean("Locked, failed to access II (type:$type, id:$id)");
		}
		return $newid;
	}
	/**
	 * Adds a Unique index constrain to the table.
	 * @param string $table
	 * @param string $col1
	 * @param string $col2
	 * @return void
	 */
	public function addUniqueIndex( $table,$columns ) {
		$table = $this->safeTable($table, true);
		sort($columns); //else we get multiple indexes due to order-effects
		foreach($columns as $k=>$v) {
			$columns[$k]=$this->safeColumn($v);
		}
		$r = $this->adapter->get("SELECT
									i.relname as index_name
								FROM
									pg_class t,
									pg_class i,
									pg_index ix,
									pg_attribute a
								WHERE
									t.oid = ix.indrelid
									AND i.oid = ix.indexrelid
									AND a.attrelid = t.oid
									AND a.attnum = ANY(ix.indkey)
									AND t.relkind = 'r'
									AND t.relname = '$table'
								ORDER BY  t.relname,  i.relname;");

		/*
		 *
		 * ALTER TABLE testje ADD CONSTRAINT blabla UNIQUE (blaa, blaa2);
		*/

		$name = "UQ_".sha1($table.implode(',',$columns));
		if ($r) {
			foreach($r as $i) {
				if (strtolower( $i["index_name"] )== strtolower( $name )) {
					return;
				}
			}
		}

		$sql = "ALTER TABLE \"$table\"
                ADD CONSTRAINT $name UNIQUE (".implode(",",$columns).")";



		$this->adapter->exec($sql);
	}

	/**
	 * Given an Database Specific SQLState and a list of QueryWriter
	 * Standard SQL States this function converts the raw SQL state to a
	 * database agnostic ANSI-92 SQL states and checks if the given state
	 * is in the list of agnostic states.
	 * @param string $state
	 * @param array $list
	 * @return boolean $isInArray
	 */
	public function sqlStateIn($state, $list) {

		$sqlState = "0";
		if ($state == "42P01") $sqlState = RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE;
		if ($state == "42703") $sqlState = RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN;
		return in_array($sqlState, $list);
	}


}
