<?php
/**
 * RedBean_OODBBean (Object Oriented DataBase Bean)
 * @file 		RedBean/RedBean_OODBBean.php
 * @description		The Bean class used for passing information
 * @author			Gabor de Mooij
 * @license			BSD
 *
 *
 * (c) G.J.G.T. (Gabor) de Mooij
 * This source file is subject to the BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class RedBean_OODBBean implements IteratorAggregate {


	private $properties = array();

	/**
	 * Meta Data storage. This is the internal property where all
	 * Meta information gets stored.
	 * @var array
	 */
	private $__info = NULL;
	
	/**
	 *
	 * @var RedBean_ToolBox
	 */
	private $toolbox;

	/**
	 * @var RedBean_LinkManager
	 */
  private $linker = null;

	/**
	 * Constructor
	 * @param RedBean_ToolBox $tools
	 */
	public function setToolbox(RedBean_ToolBox $tools) {
		$this->toolbox = $tools;
	}

	public function getIterator() {
		return new ArrayIterator($this->properties);
	}

	/**
	 * Imports all values in associative array $array. Every key is used
	 * for a property and every value will be assigned to the property
	 * identified by the key. So basically this method converts the
	 * associative array to a bean by loading the array. You can filter
	 * the values using the $selection parameter. If $selection is boolean
	 * false, no filtering will be applied. If $selection is an array
	 * only the properties specified (as values) in the $selection
	 * array will be taken into account. To skip a property, omit it from
	 * the $selection array. Also, instead of providing an array you may
	 * pass a comma separated list of property names. This method is
	 * chainable because it returns its own object.
	 * Imports data into bean
	 * @param array $array
	 * @param mixed $selection
	 * @param boolean $notrim
	 * @return RedBean_OODBBean $this
	 */
	public function import( $arr, $selection=false, $notrim=false ) {
		if (is_string($selection)) $selection = explode(",",$selection);
		//trim whitespaces
		if (!$notrim && is_array($selection)) foreach($selection as $k=>$s){ $selection[$k]=trim($s); }
		foreach($arr as $k=>$v) {
			if ($k != "__info") {
				if (!$selection || ($selection && in_array($k,$selection))) {
					$this->$k = $v;
				}
			}
		}
		return $this;
	}

	/**
	 * Exports the bean as an array.
	 * This function exports the contents of a bean to an array and returns
	 * the resulting array. If $meta equals boolean TRUE, then the array will
	 * also contain the __info section containing the meta data inside the
	 * RedBean_OODBBean Bean object.
	 * @param boolean $meta
	 * @return array $arr
	 */
	public function export($meta = false) {
		$arr = array();
		$arr = $this->properties;
		if ($meta) $arr["__info"] = $this->__info;
		return $arr;
	}

	/**
	 * Implements isset() function for use as an array.
	 * Returns whether bean has an element with key
	 * named $property. Returns TRUE if such an element exists
	 * and FALSE otherwise.
	 * @param string $property
	 * @return boolean $hasProperty
	 */
	public function __isset( $property ) {
		return (isset($this->properties[$property]));
	}
	
	/**
	 * Unset a property
	 * @param string $property
	 */
	public function remove ( $property ) {
		unset($this->properties[$property]);
	}


	/**
	 * Magic Getter. Gets the value for a specific property in the bean.
	 * If the property does not exist this getter will make sure no error
	 * occurs. This is because RedBean allows you to query (probe) for
	 * properties. If the property can not be found this method will
	 * return NULL instead.
	 * @param string $property
	 * @return mixed $value
	 */
	public function __get( $property ) {
		if (isset($this->properties[$property])) { 
		  return $this->properties[$property];
	  }
	  $bean = $this->navigateLink($property);
    if ($bean instanceof RedBean_OODBBean) {
      $this->properties[$property] = $bean;
    }
	  return $bean;
	}

	/**
	 * Magic Setter. Sets the value for a specific property.
	 * This setter acts as a hook for OODB to mark beans as tainted.
	 * The tainted meta property can be retrieved using getMeta("tainted").
	 * The tainted meta property indicates whether a bean has been modified and
	 * can be used in various caching mechanisms.
	 * @param string $property
	 * @param  mixed $value
	 */

	public function __set( $property, $value ) {

		$this->setMeta("tainted",true);
		
    // setting a bean-type property
    if ($value instanceof RedBean_OODBBean) {
      if (!$this->link($property, $value)) return;
    }
    // unlinking a bean-type property
    elseif ($value === null) {
      $cur = @$this->properties[$property];
      if ($cur instanceof RedBean_OODBBean) {
        $this->unlink($property, $cur->getMeta("type"));
        unset ($this->properties[$property]);
        return;
      }
    }
		elseif ($value===false) {
			$value = "0";
		}
		elseif ($value===true) {
			$value = "1";
		}
		$this->properties[$property] = $value;
	}
	
  protected function getLinker () {
    if (!$this->linker) {
      $this->linker = new RedBean_LinkManager($this->toolbox);
    }
    return $this->linker;
  }
  
  protected function link ($property, $value) {
    if (!$value->getMeta("type")) return false;
    $this->getLinker()->link($this, $value, $property);
    return true;
  }
  
  protected function unlink ($property, $type) {
    $this->getLinker()->breakLink($this, $type, $property);
  }
  
  protected function navigateLink ($property) {
    $pl=strlen($property);
	  if (strrpos($property, '_id')===$pl-3) return null;
    $cols = $this->toolbox->getWriter()->getColumns($this->getMeta("type"));
    foreach ($cols as $col=>$sqltype) {
      $cl=strlen($col);
      if (strpos($col, $property)===0 && strrpos($col, '_id')===$cl-3) {
        $type=substr($col, $pl+1, $cl-$pl-4);
        return $this->getLinker()->getBean($this, $type, $property);
      }
    }
  }

	/**
	 * Returns the value of a meta property. A meta property
	 * contains extra information about the bean object that will not
	 * get stored in the database. Meta information is used to instruct
	 * RedBean as well as other systems how to deal with the bean.
	 * For instance: $bean->setMeta("buildcommand.unique.0", array(
	 * "column1", "column2", "column3") );
	 * Will add a UNIQUE constaint for the bean on columns: column1, column2 and
	 * column 3.
	 * To access a Meta property we use a dot separated notation.
	 * If the property cannot be found this getter will return NULL instead.
	 * @param string $path
	 * @param mixed $default
	 * @return mixed $value
	 */
	public function getMeta( $path, $default = NULL) {
		$ref = $this->__info;
		$parts = explode(".", $path);
		foreach($parts as $part) {
			if (isset($ref[$part])) {
				$ref = $ref[$part];
			}
			else {
				return $default;
			}
		}
		return $ref;
	}

	/**
	 * Stores a value in the specified Meta information property. $value contains
	 * the value you want to store in the Meta section of the bean and $path
	 * specifies the dot separated path to the property. For instance "my.meta.property".
	 * If "my" and "meta" do not exist they will be created automatically.
	 * @param string $path
	 * @param mixed $value
	 */
	public function setMeta( $path, $value ) {
		$ref = &$this->__info;
		$parts = explode(".", $path);
		$lastpart = array_pop( $parts );
		foreach($parts as $part) {
			if (!isset($ref[$part])) {
				$ref[$part] = array();
			}
			$ref = &$ref[$part];
		}
		$ref[$lastpart] = $value;
	}

	/**
	 * Copies the meta information of the specified bean
	 * This is a convenience method to enable you to
	 * exchange meta information easily.
	 * @param RedBean_OODBBean $bean
	 * @return RedBean_OODBBean
	 */
	public function copyMetaFrom( RedBean_OODBBean $bean ) {
		$this->__info = $bean->__info;
		return $this;
	}

	/**
	 * Sleep function fore serialize() call. This will be invoked if you
	 * perform a serialize() operation.
	 *
	 * @return mixed $array
	 */
	public function __sleep() {
		//return the public stuff
		return array('properties','__info');
	}

	/**
	 * Reroutes a call to Model if exists. (new fuse)
	 * @param string $method
	 * @param array $args
	 * @return mixed $mixed
	 */
	public function __call($method, $args) {
		if (!isset($this->__info["model"])) {
			//@todo eliminate this dependency!
			$modelName = RedBean_ModelHelper::getModelName( $this->getMeta("type") );
			if (!class_exists($modelName)) return null;
			$obj = new $modelName();
			$obj->loadBean($this);
			$this->__info["model"] = $obj;
		}
		if (!method_exists($this->__info["model"],$method)) return null;
		return call_user_func_array(array($this->__info["model"],$method), $args);
	}


}

