<?php
/**
 * @name RedBean OODB
 * @file RedBean
 * @author Gabor de Mooij and the RedBean Team
 * @copyright Gabor de Mooij (c)
 * @license BSD
 *
 * The RedBean OODB Class is the main class of RedBean.
 * It takes RedBean_OODBBean objects and stores them to and loads them from the
 * database as well as providing other CRUD functions. This class acts as a
 * object database.
 *
 *
 * (c) G.J.G.T. (Gabor) de Mooij
 * This source file is subject to the BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class RedBean_OODB extends RedBean_Observable implements RedBean_ObjectDatabase {

	/**
	 *
	 * @var array
	 */
	private $stash = NULL;

	/**
	 *
	 * @var RedBean_Adapter_DBAdapter
	 */
	private $writer;
	/**
	 *
	 * @var boolean
	 */
	private $isFrozen = false;
	
	/**
	 *
	 * @var RedBean_ToolBox
	 */
	private $toolbox;

	/**
	 * The RedBean OODB Class is the main class of RedBean.
	 * It takes RedBean_OODBBean objects and stores them to and loads them from the
	 * database as well as providing other CRUD functions. This class acts as a
	 * object database.
	 * Constructor, requires a DBAadapter (dependency inversion)
	 * @param RedBean_Adapter_DBAdapter $adapter
	 */
	public function __construct( RedBean_QueryWriter $writer ) {
		$this->writer = $writer;
		$this->toolbox = new RedBean_ToolBox($this, $writer->getAdapter(), $writer);
	}

	/**
	 * Toggles fluid or frozen mode. In fluid mode the database
	 * structure is adjusted to accomodate your objects. In frozen mode
	 * this is not the case.
	 * @param boolean $trueFalse
	 */
	public function freeze( $tf ) {
		$this->isFrozen = (bool) $tf;
	}


	/**
	 * Returns the current mode of operation of RedBean.
	 * In fluid mode the database
	 * structure is adjusted to accomodate your objects.
	 * In frozen mode
	 * this is not the case.
	 * @return <type>
	 */
	public function isFrozen() {
		return (bool) $this->isFrozen;
	}

	/**
	 * Dispenses a new bean (a RedBean_OODBBean Bean Object)
	 * of the specified type. Always
	 * use this function to get an empty bean object. Never
	 * instantiate a RedBean_OODBBean yourself because it needs
	 * to be configured before you can use it with RedBean. This
	 * function applies the appropriate initialization /
	 * configuration for you.
	 * @param string $type
	 * @return RedBean_OODBBean $bean
	 */
	public function dispense($type ) {
		$this->signal( "before_dispense", $type );
		$bean = new RedBean_OODBBean();
		$bean->setToolbox($this->toolbox);
		$bean->setMeta("type", $type );
		$idfield = $this->writer->getIDField($bean->getMeta("type"));
		$bean->setMeta("sys.idfield",$idfield);
		$bean->$idfield = 0;
		if (!$this->isFrozen) $this->check( $bean );
		$bean->setMeta("tainted",false);
		$this->signal( "dispense", $bean );
		return $bean;
	}

	/**
	 * Checks whether a RedBean_OODBBean bean is valid.
	 * If the type is not valid or the ID is not valid it will
	 * throw an exception: RedBean_Exception_Security.
	 * @throws RedBean_Exception_Security $exception
	 * @param RedBean_OODBBean $bean
	 */
	public function check( RedBean_OODBBean $bean ) {
		$idfield = $this->writer->getIDField($bean->getMeta("type"));
		//Is all meta information present?

		if (!isset($bean->$idfield) ) {
			throw new RedBean_Exception_Security("Bean has incomplete Meta Information $idfield ");
		}
		if (!($bean->getMeta("type"))) {
			throw new RedBean_Exception_Security("Bean has incomplete Meta Information II");
		}
		//Pattern of allowed characters
		$pattern = '/[^abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\.]/';
		//Does the type contain invalid characters?
		if (preg_match($pattern,$bean->getMeta("type"))) {
			throw new RedBean_Exception_Security("Bean Type is invalid");
		}
		//Are the properties and values valid?
		foreach($bean as $prop=>$value) {
			if ($value instanceof RedBean_OODBBean) continue; 
			if (
			is_array($value) ||
					  is_object($value) ||
					  strlen($prop)<1 ||
					  preg_match($pattern,$prop)
			) {
				throw new RedBean_Exception_Security("Invalid Bean: property $prop  ");
			}
		}
	}


	/**
	 * Checks whether the specified table already exists in the database.
	 * Not part of the Object Database interface!
	 * @param string $table
	 * @return boolean $exists
	 */
	public function tableExists($table) {
		//does this table exist?
		$tables = $this->writer->getTables();
		return in_array($this->writer->getFormattedTableName($table), $tables);
	}

	/**
	 * Stores a bean in the database. This function takes a
	 * RedBean_OODBBean Bean Object $bean and stores it
	 * in the database. If the database schema is not compatible
	 * with this bean and RedBean runs in fluid mode the schema
	 * will be altered to store the bean correctly.
	 * If the database schema is not compatible with this bean and
	 * RedBean runs in frozen mode it will throw an exception.
	 * This function returns the primary key ID of the inserted
	 * bean.
	 * @throws RedBean_Exception_Security $exception
	 * @param RedBean_OODBBean $bean
	 * @return integer $newid
	 */
	public function store( RedBean_OODBBean $bean ) {
		$this->signal( "update", $bean );
		if (!$this->isFrozen) $this->check($bean);
		//what table does it want
		$table = $bean->getMeta("type");
		$idfield = $this->writer->getIDField($table);
		//Does table exist? If not, create
		if (!$this->isFrozen && !$this->tableExists($table)) {
			$this->writer->createTable( $table );
		}
		if (!$this->isFrozen) {
			$columns = $this->writer->getColumns($table) ;
		}
		//does the table fit?
		$insertvalues = array();
		$insertcolumns = array();
		$updatevalues = array();
		foreach( $bean as $p=>$v ) {
			if (($p!=$idfield) && ($v instanceof RedBean_OODBBean != true)) {
				if (!$this->isFrozen) {
					//Does the user want to specify the type?
					if ($bean->getMeta("cast.$p",-1)!==-1) {
						$cast = $bean->getMeta("cast.$p");
						if ($cast=="string") {
							$typeno = $this->writer->scanType("STRING");
						}
						else {
							throw new RedBean_Exception("Invalid Cast");
						}
					}
					else {
						//What kind of property are we dealing with?
						$typeno = $this->writer->scanType($v);
					}
					//Is this property represented in the table?
					if (isset($columns[$p])) {
						//yes it is, does it still fit?
						$sqlt = $this->writer->code($columns[$p]);
						if ($typeno > $sqlt) {
							//no, we have to widen the database column type
							$this->writer->widenColumn( $table, $p, $typeno );
						}
					}
					else {
						//no it is not
						$this->writer->addColumn($table, $p, $typeno);
					}
				}
				//Okay, now we are sure that the property value will fit
				$insertvalues[] = $v;
				$insertcolumns[] = $p;
				$updatevalues[] = array( "property"=>$p, "value"=>$v );
			}
		}
		if (!$this->isFrozen && ($uniques = $bean->getMeta("buildcommand.unique"))) {
			foreach($uniques as $unique) {
				$this->writer->addUniqueIndex( $table, $unique );
			}
		}
		if ($bean->$idfield) {
			if (count($updatevalues)>0) {
				$this->writer->updateRecord( $table, $updatevalues, $bean->$idfield );
			}
			$bean->setMeta("tainted",false);
			$this->signal( "after_update", $bean );
			return (int) $bean->$idfield;
		}
		else {
			$id = $this->writer->insertRecord( $table, $insertcolumns, array($insertvalues) );
			$bean->$idfield = $id;
			$bean->setMeta("tainted",false);
			$this->signal( "after_update", $bean );
			return (int) $id;
		}
	}

	/**
	 * Loads a bean from the object database.
	 * It searches for a RedBean_OODBBean Bean Object in the
	 * database. It does not matter how this bean has been stored.
	 * RedBean uses the primary key ID $id and the string $type
	 * to find the bean. The $type specifies what kind of bean your
	 * are looking for; this is the same type as used with the
	 * dispense() function. If RedBean finds the bean it will return
	 * the RedBean_OODB Bean object; if it cannot find the bean
	 * RedBean will return a new bean of type $type and with
	 * primary key ID 0. In the latter case it acts basically the
	 * same as dispense().
	 * If the bean cannot be found in the database a new bean of
	 * the specified type will be generated and returned.
	 * @param string $type
	 * @param integer $id
	 * @return RedBean_OODBBean $bean
	 */
	public function load($type, $id) {
		$this->signal("before_open",array("type"=>$type,"id"=>$id));
		$tmpid = intval( $id );
		if ($tmpid < 0) throw new RedBean_Exception_Security("Id less than zero not allowed");
		$bean = $this->dispense( $type );
		if ($this->stash && isset($this->stash[$id])) {
			$row = $this->stash[$id];
		}
		else {
			try {
				$rows = $this->writer->selectRecord($type,array($id));
			}catch(RedBean_Exception_SQL $e ) {
				if (
				$this->writer->sqlStateIn($e->getSQLState(),
				array(
				RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
				RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
				)

				) {
					$rows = 0;
					if ($this->isFrozen) throw $e; //only throw if frozen;
				}
				else throw $e;
			}
			if (!$rows) return $this->dispense($type);
			$row = array_pop($rows);
		}
		foreach($row as $p=>$v) {
			//populate the bean with the database row
			$bean->$p = $v;
		}
		$this->signal( "open", $bean );
		$bean->setMeta("tainted",false);
		return $bean;
	}

	/**
	 * Removes a bean from the database.
	 * This function will remove the specified RedBean_OODBBean
	 * Bean Object from the database.
	 * @throws RedBean_Exception_Security $exception
	 * @param RedBean_OODBBean $bean
	 */
	public function trash( RedBean_OODBBean $bean ) {
		$idfield = $this->writer->getIDField($bean->getMeta("type"));
		$this->signal( "delete", $bean );
		if (!$this->isFrozen) $this->check( $bean );
		try {
			$this->writer->deleteRecord( $bean->getMeta("type"), $bean->$idfield );
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}
		$this->signal( "after_delete", $bean );
		
	}

	/**
	 * Loads and returns a series of beans of type $type.
	 * The beans are loaded all at once.
	 * The beans are retrieved using their primary key IDs
	 * specified in the second argument.
	 * @throws RedBean_Exception_Security $exception
	 * @param string $type
	 * @param array $ids
	 * @return array $beans
	 */
	public function batch( $type, $ids ) {
		if (!$ids) return array();
		$collection = array();
		try {
			$rows = $this->writer->selectRecord($type,$ids);
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;

			$rows = false;
		}
		$this->stash = array();
		if (!$rows) return array();
		foreach($rows as $row) {
			$this->stash[$row[$this->writer->getIDField($type)]] = $row;
		}
		foreach($ids as $id) {
			$collection[ $id ] = $this->load( $type, $id );
		}
		$this->stash = NULL;
		return $collection;
	}

	/**
	 * This is a convenience method; it converts database rows
	 * (arrays) into beans.
	 * @param string $type
	 * @param array $rows
	 * @return array $collectionOfBeans
	 */
	public function convertToBeans($type, $rows) {
		$collection = array();
		$this->stash = array();
		foreach($rows as $row) {
			$id = $row[$this->writer->getIDField($type)];
			$this->stash[$id] = $row;
			$collection[ $id ] = $this->load( $type, $id );

		}
		$this->stash = NULL;
		return $collection;
	}

	/**
	 * Returns the number of beans we have in DB of a given type.
	 * 
	 * @param string $type type of bean we are looking for
	 * 
	 * @return integer $num number of beans found 
	 */
	public function count($type) {
		try {
			return (int) $this->writer->count($type);
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}
		return 0;
	}

	/**
	 * Trash all beans of a given type.
	 *
	 * @param string $type type
	 *
	 * @return boolean $yesNo whether we actually did some work or not..
	 */
	public function wipe($type) {
		try {
			$this->writer->wipe($type);
			return true;
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}
		return false;
	}


}


