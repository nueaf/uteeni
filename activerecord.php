<?php
/*
 * 	Base class for uteeni active record
 * 	Developed by Kristian Nissen and Michael Als @ eteneo ApS
 */
require_once dirname(__FILE__) . '/memchached.php';
require_once dirname(__FILE__) . '/sql_syntaxor.php';
require_once dirname(__FILE__) . '/criterion.php';
require_once dirname(__FILE__) . '/criteria.php';
@include_once dirname(__FILE__) . '/query.php'; //This may not be able for older versions of AR

/**
 * Exception class used in cases where use of an unknown property is attempted.
 */
class ActiveRecordPropertyException extends Exception {

}

/**
 * Exception class used in cases where an unknown method call is attempted.
 */
class ActiveRecordMethodException extends BadMethodCallException {

}
/**
 * Exception class used in cases where a validation fails.
 */
class ActiveRecordValidationException extends Exception {

}

/**
 * The active record base class.
 *
 * All classes describing tables and tablerows should inherit from this or
 * subclasses of this.
 */
class ActiveRecord {

	protected $table_name;
    protected $database;
    protected $properties;
    public $unmodified_properties;
    public $raw;
    protected $meta;
    protected $associations = Array();
    protected $foreign_keys = Array();
    static public $db;
    protected $affected_rows = null;
    private $cache_prepend = 'ar';
	protected $specific_model_hooks = array();
	protected static $generic_hooks = array();

    /**
     * Constructor for the active record object.
     *
     * The constructor accepts an associative array of values for the initial
     * object. This array may be used for quick initialisation of the object.
     *
     * If the ignoreFailure parameter is set the array will be allowed to hold
     * keys, which are unknown to the receiving object. Otherwise an axception
     * will be thrown if the keys cannot be understood by the receiving class.
     *
     * @param array $values The initial values for the object.
     * @param boolean $ignoreFailure Will make the constructor catch exceptions and carry on its work as if nothing happened.
     * @throws ActiveRecordPropertyException if an unknown property is provided and ignoreFailure is false.
     */
    function __construct($values = array(), $ignoreFailure=false) {
        //Make sure we have an array
        if (!is_array($values)) {
            $values = (array) $values;
        }

        //Set all the received data
        try {
            foreach ($values as $key => $value) {
                $this->$key = $value;
            }
        } catch (ActiveRecordPropertyException $e) {
            //On error we may rethrow the exception
            if (!$ignoreFailure)
                throw $e;
        }
    }

    /**
     * Returns the expected classname for a certain tablename.
     *
     * The transformation in names is very similar to a underscore to camelcase
     * transformation.
     *
     * @param string $table
     * @return string The expected class name
     */
    public static function getClassnameFromTablename($table, $database = null) {
        $table = ucfirst(strtolower($table));
		if(!$database){
			$instance = new $table();
			$database = $instance->database."Database";
		}
		if(!ActiveRecordDatabase::$dbconfig){
			ActiveRecordDatabase::load($database);
		}
        $func = create_function('$c', 'return strtoupper($c[1]);');
        return ActiveRecordDatabase::$dbconfig[$database]['prefix'] . preg_replace_callback('/_([a-z])/', $func, $table) . ActiveRecordDatabase::$dbconfig[$database]['suffix'];
    }

    /**
     * Returns a string representation of the object.
     *
     * The representation will be the same as a print_r of the properties in an
     * array, but with the classname noted in stead of the default "Array".
     *
     * Only properties placed directly in the table will be output, whereas
     * associations wont be described in any way.
     *
     * @return string
     */
    function __toString() {
        $class = get_class($this);
        $print_r = print_r($this->getValues(), true);
        return $class . substr($print_r, 5);
    }

    /**
     * Magic setter method for setting model fields.
     *
     * Only fields, which are present in the table or as an association can be
     * set.  Potentially aliases may be set if a __set_[name] method is
     * available.
     *
     * The __set_[name] methods may be used to provide a
     * __set_date_timetamp(timestamp) method, which sets the date field provided
     * a timetamp. Likewise the __set_date method may be used to simply override
     * the normal way of setting the date property.
     *
     * @todo: Figure out if setting an association should change the underlying relation properties.
     *
     * @param string $name The name of the property to set
     * @param string $value The value of the field to be set
     */
    function __set($name, $value) {
        if (method_exists($this, $method = "__set_$name")) {
            $this->$method($value);
        } elseif (array_key_exists($name, $this->properties)) {
            $this->properties[$name] = $this->validate_data($name, $value);
        } elseif ($this->hasAssociation($name)) {
            $this->properties[$name] = $value;
        } else {
            $class = get_class($this);
            $msg = "$name not valid field for $class";
            throw new ActiveRecordPropertyException($msg);
        }
    }

    /**
     * Magic getter method for getting model fields.
     *
     * Only fields which are present in the table or as an association can be
     * retreived. Potentially aliases may be retreived if a __get_[name] method
     * is available.
     *
     * The __get_[name] methods may be used to provide a __get_date_timestamp()
     * method, which returns the value of the date field as a timestamp.
     * Likewise the __get_date method may be used to override the normal way of
     * accessing the date property.
     *
     * @param string $name The name of the property to retreive
     */
    function __get($name) {
        if (method_exists($this, $method = "__get_$name")) {
            return $this->$method();
        } elseif (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        } else if ($this->hasAssociation($name)) {
            return $this->fetch_assoc($name);
        } else {
            return null;
        }
    }

    /**
     * Magic call method which provides the following function prototypes:
     *
     * - find_by_[x]:       Allows user to fetch a row, which is found using a
     *                      certain column as search parameter. The method takes
     *                      one parametert being the value to search for.
     *
     * - find_all_by_[x]:   Allows user to fetch multiple rows, which are found
     *                      using a certain column as search parameter. The
     *                      method takes one parametert being the value to
     *                      search for.
     *
     * - list_[x]_by_[y]:   Returns a list of the values in [x] filtered with
     *                      the column [y]. column [y] has to match the first
     *                      parameter given in the call.
     *                      Optionally a second options parameter may be given.
     *
     * - [x]:               if [x] is the name of an association the association
     *                      will be fetched. The method call allows a single
     *                      parameter being an array of options for the
     *                      syntaxor. In general the paramteres should newer
     *                      overwrite the values for SELECT, TABLE, JOIN or
     *                      WHERE
     *
     * - get_[x]_[y]:       Fetches the aggregate function [x] on the field [y].
     *                      For instance get_max_price will retreive the maximum
     *                      price in the table. Optionally a single argument may
     *                      be given in the form of a where clause.
     *
     * @param string $function The funciton name called
     * @param array $args The arguments passed to the function
     * @return mixed
     */
    function __call($function, $args) {
        $matches = array();
        if (stripos($function, 'find_by_') !== false) {
            $method_name = substr($function, 8);
            return $this->find_by_property($method_name, $args[0]);
        } elseif (stripos($function, 'find_all_by_') !== false) {
            $method_name = substr($function, 12);
            return $this->find_all_by_property($method_name, $args[0]);
        } elseif (preg_match("/list_(.*?)_by_(.*)/", $function, $matches)) {
            $options = array_key_exists(1, $args) ? $args[1] : null;
            return $this->list_by_property($matches[1], $matches[2], $args[0], $options);
		} else if ($this->hasAssociation($function)) {
            return $this->fetch_assoc($function, count($args)?$args[0]:array());
        } else if (preg_match("/get_(.*?)_(.*)/", $function, $matches) && array_key_exists($matches[2], $this->meta)) {
            $where = array_key_exists(0, $args) ? $args[0] : null;
            return $this->getAggregateValue($matches[1], $matches[2], $where);
        } else {
            $class = get_class($this);
            $msg = "Call to undefined method $function on class: '$class'";
            throw new ActiveRecordMethodException($msg);
        }
    }

	function __isset($name) {
		return isset($this->properties[$name]);
	}

    /**
     * Method for retreiving the table name.
     *
     * @return string The name of the table.
     */
    public function getTableName() {
        return $this->table_name;
    }

    /**
     * Get the name of the database connection.
     *
     * @return string The name of the database connection
     */
    public function getDatabaseName() {
        return $this->database;
    }

    /**
     * Returns the dabase connection to the database.
     *
     * @return PDO
     */
    public function getDatabaseConnection() {
        return Database::connect($this->getDatabaseName());
    }

    /**
     * Returns true if a column of the given name exists.
     *
     * @param string $name The name of the column
     * @return bool True if the column exists.
     */
    public function hasColumn($name) {
        return array_key_exists($name, $this->meta);
    }

    /**
     * Method for retreiving a list of available properties on the object.
     *
     * @return array Array of column=>info entries simmilar to the meta property on this class.
     */
    function getProperties() {
        return $this->meta;
    }

    /**
     * Method for fecthing the values on the object as an array.
     *
     * This method will return the values of the column in array form. If only
     * some columns where fetched with a limited select clause, only the
     * selected fields will be returned.
     *
     * If $noAssociations is false associations may also be returned. In this
     * case only associations which have allready been fetched will be part of
     * the returned array.
     *
     * @param boolean $noAssociations If true only column values will be returned.
     * @return Array Array of properties.
     */
    function getValues($noAssociations = false) {
        if ($noAssociations) {
            $result = Array();
            foreach (array_keys($this->meta) as $name) {
                $result[$name] = $this->$name;
            }
        } else {
            $result = $this->properties;
        }
        return $result;
    }

    /**
     * Method for fetching the database data and certain associations as array.
     *
     * With no parameters given this method will function just like the
     * getValues(true) method. The extension comes from the $assoc parameter.
     * The $assoc parameter allows associations to be specified as
     * association=>array pairs. The association described as the key will also
     * be placed in the array by a recursive call to getValuesAssoc on the
     * associated data. For the recursive call the array value will be used as
     * parameter, thereby allowing the array to hold nested structures.
     *
     * @param array $assoc See the description above.
     * @return Array Array of field=>values
     */
    function getValuesAssoc(array $assoc=Array()) {
        $result = $this->getValues(true);

        foreach ($assoc as $association => $subassoc) {
            $data = $this->$association;
            if (is_array($data)) {
                $result[$association] = Array();
                foreach ($data as $item) {
                    $result[$association][] = $item->getValuesAssoc($subassoc);
                }
            } elseif (is_a($data, "ActiveRecord")) {
                $result[$association] = $data->getValuesAssoc($subassoc);
            } else {
                $result[$association] = $data;
            }
        }
        return $result;
    }

    /**
     * Method for finding the primary key of the table.
     *
     * If the primary key is a combined key, only the first column part of the
     * primary key is returned.
     *
     * If ACTIVERECORD_STRICT_FIND_ONE is set and the table holds more than one
     * primary key this method will throw an exception.
     *
     * @return string The name of the primary key.
     */
    public function find_primary() {
        $constname = "ACTIVERECORD_STRICT_FIND_ONE";
        $strict = defined($constname) && constant($constname);

        $primaries = $this->find_primaries();
        if ($strict && count($primaries)>1) {
            throw new Exception("Multiple primary keys found for table: {$this->tablename}");
        }

        if (count($primaries)) {
            return $primaries[0];
        }

        return null;
    }

    /**
     * Fetches a list of primary keys.
     *
     * This method will return an array holding all the names of primary key
     * columns. The result may hold 0, 1 or more column names. All returned
     * names are columns in the table.
     *
     * @return array Of primary key column names.
     */
    public function find_primaries() {
        $primaries = array();
        foreach ($this->meta as $key => $value) {
            if ($value["primary"] === true)
                $primaries[] = $key;
        }
        return $primaries;
    }

    /**
     * Method for finding all timestamp columns in the table using automatic times.
     *
     * This method will search for all timestamps and return them in two array.
     * The first array "update" will hold all timestamps with a timestamp_update
     * value which evaluates to true, and the second array "create" will hold
     * similar columns which uses timestamp_create. A column may be described in
     * both arrays at the same time.
     *
     * The result is an array with the keys "update" and "create" holding the
     * two arrays.
     *
     * @return Array Array of columns of the type timestamp
     */
    protected function find_timestamps() {
        $timestamps = array();
        foreach ($this->meta as $key => $value) {
            if (isset($value["timestamp_update"]) && $value["timestamp_update"] === true)
                $timestamps['update'] = $key;
            if (isset($value["timestamp_create"]) && $value["timestamp_create"] === true)
                $timestamps['create'] = $key;
        }
        return $timestamps;
    }

    /**
     * Returns true if an association of the given name exists
     *
     * @param string $name The name of the association.
     * @return boolean True if the association exists.
     */
    public function hasAssociation($name) {
        if (property_exists($this, "foreign_keys") && isset($this->foreign_keys)) {
            $this->associations = array_merge($this->associations, $this->foreign_keys);
            unset($this->foreign_keys);
        }

        return array_key_exists($name, $this->associations);
    }

    public function getAssociations() {
        if (property_exists($this, "foreign_keys") && isset($this->foreign_keys)) {
            $foreigns = array_keys($this->foreign_keys);
            $this->hasAssociation($foreigns[0]);
        }

        return array_keys($this->associations);
    }

    /**
     * Returns the association information array.
     *
     * This array can be expected to hold the following entries:
     * - class:                 Normally the name of the TABLE holding the new
     *                          value. This may however also be the name of the
     *                          active_record class.
     *
     * - class_is_table         Autogenerated boolean, which will be true if the
     *                          class field holds a table name.
     *
     * - real_class             Autogenerated string holding the real classname
     *                          for the active record class if the class field
     *                          holds a table name. Otherwise this field will
     *                          hold the same as class.
     *
     * - local_property:        The name of the field on the local class, which
     *                          is part of the relation.
     *
     * - join_local_property:   Optional field, which is only present if it is a
     *                          many2many relation. This will hold the name of
     *                          the field on the join table, relating to the
     *                          local_property field.
     *
     * - join_table:            Optional field, which is only present it it is a
     *                          many2many relation. This will hold the name of
     *                          the join table. This is ALWAYS the table name.
     *
     * - join_class_property:   Optional field, which is only present it it is a
     *                          many2many relation. This will hold the name of
     *                          the remote field in the join table.
     *
     * _ class_property:        The name of the relation field in the remote
     *                          class.
     *
     * @param string $name The name of the association.
     * @return array The association information or null if no such association
     * exists.
     */
    public function getAssociationInfo($name) {
        if (!$this->hasAssociation($name)) {
            return null;
        }

        $assoc = & $this->associations[$name];

        if (!array_key_exists("real_class", $assoc)) {
            if (class_exists($assoc["class"]) && is_subclass_of($assoc["class"], "ActiveRecord")) {
                $assoc["real_class"] = $assoc["class"];
            } else {
                $assoc["real_class"] = self::getClassnameFromTablename($assoc["class"], $this->database . "Database");
            }

            $assoc["class_is_table"] = ($assoc["real_class"] == $assoc["class"]);
        }

        return $assoc;
    }

    /**
     * Determines whether or not a row has association data related to it
     * @param array $ignore_relations
     * @return boolean True if there are association data related to the row
     */
    public function hasAssociationData($ignore_relations = Array()) {
		$this->getAssociations();
    	if ( !$this->associations ) return FALSE;
		foreach ( array_keys($this->associations) AS $class ) {
			if ( in_array($class, $ignore_relations) ) continue; // ed. typically a created_by relation wants to be ignored
			if ( $this->fetch_assoc($class) ) {
				return TRUE;
			}
		}
		return FALSE;
    }

	/**
	 * Finds and returns the name of the association which points backwars
	 * compared to the association named by the $assoc param.
	 *
	 * If not reverse is found null is returned.
	 *
	 * @param $assoc The association to find the reverse for
	 * @return Array("class"=>$cls, "assoc"=>$assoc) The class holding the association and the name of the association.
	 */
	public function reversedJoin($assoc) {
		$assocInfo = $this->getAssociationInfo($assoc);
		$cls = $assocInfo["real_class"];
		$obj = new $cls;
		$assocs = $obj->getAssociations();
		foreach ($assocs as $assoc) {
			$reverseAssocInfo = $obj->getAssociationInfo($assoc);
			if (is_a($this, $reverseAssocInfo["real_class"])) {
				if ($reverseAssocInfo["local_property"]==$assocInfo["class_property"]) {
					if ($reverseAssocInfo["class_property"]==$assocInfo["local_property"]) {
						if (($assocInfo["ass_type"]!="has_and_belongs_to_many" && $reverseAssocInfo["ass_type"]!="has_and_belongs_to_many") || $reverseAssocInfo["join_table"]==$assocInfo["join_table"]) {
							return Array("class"=>$cls,"assoc"=>$assoc);
						}
					}
				}
			}
		}
		return null;
	}

    /**
     * Escapes and by other means prepares a value for use in a SQL query.
     *
     * This involves escaping and quoting of text, converting integers to
     * numeric values and simmilar transformations.
     *
     * In some cases (string values) the transformations are stackable, why the
     * prepare_property method should only be applied to each value once.
     * Applying the prepare property method several times would in this case
     * result in quotes being commited to the database.
     *
     * @param string $name
     * @param mixed $value
     * @return string the value in a stringform, useable by mysql
     */
    function prepare_property($name, $value = null) {
        //If value is null, use the value of this instance's field.
        if (is_null($value)) {
            $value = $this->properties[$name];
        }

        //If the value is null return the NULL keyword.
        if (is_null($value)) {
            return "NULL";
        }

        //TODO: Find out what code uses this feature?
        if (is_array($value)) {
            if ($value['value'] && $value['method']) {
                return $value['value'];
            } else {
                throw new Exception('Cannot set array as value');
            }
        }

		//Handle the value dependent on type.
        switch ($this->meta[$name]['type']) {
            case 'datetime':
            case 'time':
            case 'timestamp':
            case 'date':
                if (strtolower($value) != "now()" && !preg_match("/^to_date\(/i", $value)) {
					$db = Database::connect($this->database);
                    $value = ($value === "" && $this->meta[$name]['required'] === false) ? "NULL" : $db->quote($value);
                }
                break;
            case 'string':
            case 'blob':
            case 'text':
            case 'enum':
                if (array_key_exists("sprintf", $this->meta[$name]) && $this->meta[$name]['sprintf']) {
                    $value = sprintf($this->meta[$name]['sprintf'], $value);
                }
				$db = Database::connect($this->database);
                $value = $db->quote($value);
                break;
            case 'integer':
                $value = intval($value);
                break;
            case 'double':
            case 'float':
                $value = floatval($value);
                break;
            default:
                $cls = get_class($this);
                error_log("ACTIVE_RECORD: Tried to prepare the property $cls.$name, but didnt know the type: {$this->meta[$name]['type']}");
                break;
        }
        return $value;
    }

    /**
     * Method for fetching an association.
     *
     * In case the association doesnt exist null is returned.
     *
     * In case the association is empty a has_many association returns null
     * rather than an empty array.
     *
     * @param string $name The name of the association
     * @param array $parms Array of extra paramteres to be used in the find_all call wrapped by this method.
     * @return mixed A single ActiveRecord instance, an array or null depending on the association type.
     */
    private function fetch_assoc($name, array $parms = array()) {
        if (!$this->hasAssociation($name))
            return null;

        $ass = $this->getAssociationInfo($name);
		
        $class = $ass['real_class'];
        $object = new $class();
        $value = $this->__get($ass['local_property']);

        switch ($ass['ass_type']) {
            case 'belongs_to':
            case 'has_one':
                if ($object->find_by_property($ass['class_property'], $value, $parms)) {
                    $result = $object;
                } else {
                    $result = null;
                }
                break;
            case 'has_many':
                $result = $object->find_all_by_property($ass['class_property'], $value, $parms);

                $constname = "ACTIVERECORD_STRICT_FIND_ONE";
                $strict = defined($constname) && constant($constname);
                if (!$strict && is_array($result) && count($result) == 0) {
                    $result = null;
                }
                break;
            case 'has_and_belongs_to_many':
                $join = "JOIN {$ass['join_table']} ON {$object->table_name}.{$ass['class_property']} = {$ass['join_table']}.{$ass['join_class_property']}";
                $where = "{$ass['join_table']}.{$ass['join_local_property']} = " . $this->prepare_property($ass['local_property']);

                $result = $object->find_all($where, null, 0, null, "ASC", $join, null, $parms);
                break;

                /* Suggested replacement code in the future. Requires that the associations to the join table is known
                $query = new ActiveRecordQuery(get_class($object));
                $query->filterBy(jointable.localref, $this->prepare_property($ass['local_property']));
                if ($object->default_order) $query->setSort($object->default_order);
                return $query->execute;
                 */
        }

        //Cache the result
        $this->properties[$name] = $result;

        //Save the result as unmodified properties
        if ($result && $this->unmodified_properties !== null) {
			$new = array();
			foreach ($result as $k => $v) {
				$new[$k] = is_object($v) ? clone $v : $v;
			}
            $this->unmodified_properties[$name] = $new;
        }

        //Return the result
        return $result;
    }

    /**
     * Finds a single row by using a property=>value pair.
     *
     * The found value is not returned, but hydrated onto the object itself.
     *
     * If the constant ACTIVERECORD_STRICT_FIND_ONE is set to true this method
     * will fail in case the search yields more than a single row. This should
     * be used to make sure fetches are done on unique constraints.
     *
     * This method accepts the optional parameter parms, which can be used to
     * alter the options array during execution of the find_all callm which this
     * method wraps. Using this requires knowledge of internal structures in
     * this class, and may be deprecated in the future.
     *
     * @param string $name The name of the field
     * @param string $value The value to match with
     * @param array $parms Array of extra options to the sqlSyntaxor used in find_all
     * @return boolean
     */
    protected function find_by_property($name, $value, $parms=Array()) {
		$this->execute_hooks("before_find");
        $constname = "ACTIVERECORD_STRICT_FIND_ONE";
        $strict = defined($constname) && constant($constname);
		$gnyffed_name = SQLSyntaxor::addGnyfToKey($name, $this->getDatabaseDriver());
        $where = "$gnyffed_name = " . $this->prepare_property($name, $value);
        $limit = $strict ? 2 : 1;

        $result = $this->find_all_assoc($where, $limit, 0, null, "ASC", null, null, $parms);

        if (count($result) != 1) {
            return false;
        }

        $this->hydrate($result[0]);
		$this->execute_hooks("after_find");
        return true;
    }

    /**
     * Finds all rows by using a property=>value pair.
     *
     * This method accepts the optional parameter parms, which can be used to
     * alter the options array during execution of the find_all callm which this
     * method wraps. Using this requires knowledge of internal structures in
     * this class, and may be deprecated in the future.
     *
     * @param string $name The name of the field
     * @param string $value The value to match with
     * @param array $parms Array of extra options to the sqlSyntaxor used in find_all
     * @return array
     */
    protected function find_all_by_property($name, $value, $parms=array()) {
		$gnyffed_name = SQLSyntaxor::addGnyfToKey($name, $this->getDatabaseDriver());
        $where = "$gnyffed_name = " . $this->prepare_property($name, $value);
        return $this->find_all($where, null, 0, null, "ASC", null, null, $parms);
    }

    /**
     * The most flexible find method taking ordered parameters.
     *
     * This method allows the construction of a query from blocks. Each block
     * relates to a block in a mysql query.
     *
     * The query form still uses strings as input, but it is sepperated and more
     * organized than using a single string for the entire query.
     *
     * Some of the fields are rather self explanatory and are only documented as
     * parameters. The rest of the fields will be described here in more detail.
     *
     * The whereclause may be either a string, or an object, which can be
     * evaluated as a string. The where paramter should evaluate as a string
     * describing a full where statement with no leading or trailing AND/OR
     * part.
     *
     * The order_by_field may be empty. In this case the model class itself may
     * provide a default_order property, which in such case will be used as
     * order_by_field. If no order_by_field nor a default_order is given the
     * $order field wont be used.
     *
     * The limit_start field wil only be used in case the limit field is greater
     * than zero.
     *
     * The joins clause is a simple string providing the part of the query
     * generating the join. Be aware that joins between two tables with simmilar
     * named columns may result in ambigous column names in where clause or
     * order clause.
     *
     * The select part allows the caller to specify certain fields for being
     * selected. This allows only selecting single columns of a table. In this
     * case, properties left out wont be fetched by lazy-loading when accessed
     * later, but simply return null.
     * The select clause can in conjunction with joins be used to select only
     * the fields from the FROM table.
     *
     * @param mixed $where              See description above.
     * @param int $limit                The number of rows to be returned
     * @param int $limit_start          The firs row to be returned
     * @param string $order_by_field    The field which to order by. This can be set to "field1 [direction], field2" to allow sorting by two fields.
     * @param string $order             The sorting of the fields (ASC/DESC). If more fields are described by order_by_field, this indicates the sorting for the last field.
     * @param string $joins             String containing all joins and joinconditions for the query.
     * @param string $select            The select clause for the query. This will default to "*" if none is given.
     * @param array $parms              Allows possible options for SQLSyntaxor to be set. This should only be used of internal methods are known.
     * @return array of active record models matching the query
     */
    protected function find_all_assoc($where = null, $limit = null, $limit_start = 0, $order_by_field = '', $order = 'ASC', $joins = "", $select = "", $parms=Array()) {
		$optionsArray = array("TABLE" => $this->table_name);

	if ( isset($this->_cache) && $this->_cache ){
		$cKey = $this->cache_prepend.':'.$this->table_name.':'.sha1(serialize(func_get_args()));
		$cvalue = mCached::get($cKey);
                if ( $cvalue ){
			error_log('GOT IN CACHE: '.$this->table_name.' = '.$cKey);
                        return unserialize($cvalue);
		}
	}
	

        //Where clause
        if ($where) {
            if (is_object($where)) {
                switch (strtolower(get_class($where))) {
                    case 'criteria':
                        $where->setmodel($this);
                        break;
                    case 'arcriterion':
                    case 'criterion':
                        $where->model = $this;
                        break;
                }
            }
            $optionsArray['WHERE'] = $where;
        }

        //Order by clause
        if ($order_by_field!==null) {
            if ($order_by_field!="") {
                $optionsArray['ORDERFIELD'] = $order_by_field;
                $optionsArray['ORDERTYPE'] = $order;
            }
        } elseif (property_exists($this, "default_order")) {
            $optionsArray['ORDERFIELD'] = $this->default_order;
            $optionsArray['ORDERTYPE'] = $order;
        }

        //Limiting the query
        if ($limit) {
            $limit_start = is_numeric($limit_start) ? $limit_start : 0;
            $limit = is_numeric($limit) ? $limit : 0;
            if ($limit > 0) {
                $optionsArray['LIMIT'] = $limit;
                $optionsArray['OFFSET'] = $limit_start;
            }
        }

        if ($joins) {
            $optionsArray['JOINS'] = $joins;
        }

        if ($select) {
            $optionsArray['SELECT'] = $select;
        }

        //Execute the query
        $db = $this->getDatabaseConnection();
        $sql = SQLSyntaxor::getSelectSQL($optionsArray, $this->getDatabaseDriver());

	$result = $this->query($sql,$db);
        $arr = array();
        if ($result) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $arr[] = $row;
            }
        }
	if ( $this->_cache ){
		mCached::set($cKey,serialize($arr));
		$cKeys = mCached::get($this->cache_prepend.':list:'.$this->tablename);
		if ( $cKeys ){
			$all_cache_keys = unserialize($cKeys);
		} else {
			$all_cache_keys = array();
		}
		$all_cache_keys[] = $cKey;
		mCached::set($this->cache_prepend.':list:'.$this->tablename,serialize($all_cache_keys));
	}
        return $arr;
    }

    public function find_all($where = null, $limit = null, $limit_start = 0, $order_by_field = '', $order = 'ASC', $joins = "", $select = "", $parms=Array()) {
		$this->execute_hooks("before_find");
        $assoc = $this->find_all_assoc($where, $limit, $limit_start, $order_by_field, $order, $joins, $select, $parms);

        $arr = array();
        foreach ($assoc as $row) {
            $tmp = new $this();
            $tmp->hydrate($row);
            $arr[] = $tmp;
        }
		$this->execute_hooks("after_find", $arr);
        return $arr;
    }

    /**
     * Fetches a set of rows from database from a sql query.
     *
     * This allows the execution of custom SQL against the same database as the
     * model instance it is called on. The result will be in the form of an
     * array of stdObject classes and NOT ActiveRecord classes.
     *
     * @param string $sql String containing the full SQL statement to be executed.
     * @return array Array of objects describing the resulting rows.
     */
    function find_by_sql($sql) {
	if ( isset($this->_cache) && $this->_cache ){
		$cKey = $this->cache_prepend.':'.$this->table_name.':'.sha1(serialize(func_get_args()));
		$cvalue = mCached::get($cKey);
                if ( $cvalue ){
			error_log('GOT IN CACHE: '.$this->table_name.' = '.$cKey);
                        return unserialize($cvalue);
		}
	}
        if (stripos($sql, "select") !== 0 || preg_match("/\b(update|delete|insert)\b/i", $sql)) {
            throw new Exception("Only Select statements are allowed in find_by_sql!");
        }

        $db = Database::connect($this->database);
		$result = $this->query($sql,$db);
		if(!$result){
            $error = $db->errorInfo();
            trigger_error($error[2] . ": $sql");
            return false;
        }
        $return_val = array();
        while ($row = $result->fetchObject()) {
            $return_val[] = $row;
        }
	if ( $this->_cache ){
		mCached::set($cKey,serialize($return_val));
		$cKeys = mCached::get($this->cache_prepend.':list:'.$this->tablename);
		if ( $cKeys ){
			$all_cache_keys = unserialize($cKeys);
		} else {
			$all_cache_keys = array();
		}
		$all_cache_keys[] = $cKey;
		mCached::set($this->cache_prepend.':list:'.$this->tablename,serialize($all_cache_keys));
	}
        return $return_val;
    }

    /**
     * Simplified alias for find_all.
     *
     * This method is a direct wrapper of find_all with no logic at all. This
     * method should not be used, as find_all provides the same functionality
     * with more flexibility and this method may be deprecated in the future.
     *
     * @param mixed $where              See description above.
     * @param int $limit                The number of rows to be returned
     * @param int $limit_start          The firs row to be returned
     * @param string $order_by_field    The field which to order by. This can be set to "field1 [direction], field2" to allow sorting by two fields.
     * @param string $order             The sorting of the fields (ASC/DESC). If more fields are described by order_by_field, this indicates the sorting for the last field.
     * @return array Array of the matching rows.
     */
    function read($where = null, $limit = null, $limit_start = 0, $order_by_field = '', $order = 'ASC') {
        return $this->find_all($where, $limit, $limit_start, $order_by_field, $order);
    }

    /**
     * Method for fetching the first available item from a find_all operation.
     *
     * This method allows the execution of a find_all with an optional where
     * clause. From the result only the first found element will be returned.
     *
     * This method will be deprecated in future versions, as most usecases for
     * this method is handled by find_by_property or find_all.
     *
     * @param mixed $where A where clause acceptable by find_all
     * @return ActiveRecord The first row matching the where clause.
     */
    function find_first($where = null) {
        return array_shift($this->find_all($where, 1));
    }

    /**
     * Method for fetching the row identified by a primary key value.
     *
     * This method behaves differently depending on the
     * ACTIVERECORD_STRICT_FIND_ONE constant.
     *
     * If ACTIVERECORD_STRICT_FIND_ONE is not set (old behaviour). Primary keys
     * are expected to be a single value. If the primary key is a combined key,
     * only the first field is matched against value, and the first matching row
     * will be used.
     *
     * If ACTIVERECORD_STRICT_FIND_ONE is set, multiple primary keys are
     * allowed. In this case the method will function the same way for tables
     * with a single primary key field.
     * For combined primary keys however, the method will expect an array of
     * values, which will be mapped against the primary key columns. If the
     * array holds a key name matching the primary key field, the corresponding
     * value will be used. Otherwise the index of the value matching the index
     * of the primary key column is used.
     * If an array is given for a single primary key, or some fields cannot be
     * matched in a combined primary key table, this method will fail and return
     * false.
     *
     * @param mixed $value The value to match for the primary key.
     * @return boolean Returns true if a value was found and hydrated onto this.
     */
    function find($value) {
        $primary = $this->find_primaries();

        $constname = "ACTIVERECORD_STRICT_FIND_ONE";
        $strict = defined($constname) && constant($constname);

        if (!$strict || count($primary) == 1) {
            //Old behaviour
            if (count($primary) == 0) {
                return false;
            } else {
                return $this->find_by_property($primary[0], $value);
            }
        } else {
            //New behavior for combined keys
            if (!is_array($value))
                return false;

            $where = Array();
            foreach ($primary as $index => $key) {
				$key = SQLSyntaxor::addGnyfToKey($key, $this->getDatabaseDriver());
                if (array_key_exists($key, $value))
                    $where[] = "$key = " . $this->prepare_property($key, $value[$key]);
                elseif (array_key_exists($index, $value))
                    $where[] = "$key = " . $this->prepare_property($key, $value[$index]);
                else
                    return false;
            }

            $result = $this->find_all_assoc(implode(" AND ", $where), 2);
            if (count($result) != 1)
                return false;

            $this->hydrate($result[0]);
			$this->execute_hooks("after_find");
            return true;
        }
    }

    /**
     * Method for fetching the result of an aggregate function.
     *
     * This method is called by the magic __call method if the function name
     * matches get_[x]_[y]. See __call documentation for further information.
     *
     * @param string $function The aggregate function to be used.
     * @param string $field The field to use the aggregate function on.
     * @param string $where A potentially limiting where clause.
     * @param string $joins The join part of the query.
     * @return mixed The output of the aggregate function.
     */
    protected function getAggregateValue($function, $field, $where="", $joins="") {
        $options = array();
        $options["TABLE"] = $this->table_name;
        $options["WHERE"] = trim("$where");
        $options["SELECT"] = "$function($field)";

        if (strlen($options["WHERE"]) == 0) {
            unset($options["WHERE"]);
        }

        $db = $this->getDatabaseConnection();
        $sql = SQLSyntaxor::getSelectSQL($options, $this->getDatabaseDriver());

        $result = $this->query($sql, $db);
        if (!$result)
            return null;

        return $result->fetchColumn();
    }

    /**
     * Returns a list of values for a single column in the table, restricted by
     * some other set of constraints.
     *
     * @param string $field_to_list The name of the column to extract.
     * @param string $name The field we are using for a where clause
     * @param string $value The value we are using for a where clause
     * @param array $options Additional options. May hold all options besides TABLE and WHERE.
     * @return array
     */
    protected function list_by_property($field_to_list, $name, $value, $options) {
		$db = $this->getDatabaseConnection();
		$driver = $this->getDatabaseDriver();
        $where = SQLSyntaxor::addGnyfToKey($name, $driver) . " = " . $this->prepare_property($name, $value);
        if ($options['WHERE']) {
            $where .= " AND " . $options['WHERE'];
        }

        $options["TABLE"] = $this->table_name;
        $options["WHERE"] = $where;
        $options["SELECT"] = $field_to_list;

        $sql = SQLSyntaxor::getSelectSQL($options, $driver);
        $result = $this->query($sql, $db);
        if (!$result)
            return array();

        return $result->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Method for getting the number of rows a query will return.
     *
     * This method is basically a wrapper for getAggregateValue or
     * get_count_1($where). For this reason this method may be deprecated in
     * future versions.
     *
     * @param string $where String holding the where clause for the parameter
     * @param string $joins String holding te join part of the query
     * @return type The number of rows the where and join parameter will yield.
     */
    function select_count($where = null, $joins=Array()) {
        return $this->getAggregateValue("COUNT", 1, $where, $joins);
    }

    /**
     * Method for setting up the properties on the object from a database row.
     *
     * This method will attempt to fetch the field values from the received
     * array and set them on the current object.
     *
     * The received values are used as the unmodified or original properties,
     * why the hydrated values will be used as the base for calculating dirty
     * fields.
     *
     * @param type $row
     */
    function hydrate($row) {
        $this->raw = $row;
        foreach (array_keys($this->meta) as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $this->unmodified_properties[$key] = $this->properties[$key] = $this->validate_data($key, $row[$key]);
            } else {
                $this->unmodified_properties[$key] = $this->properties[$key] = null;
            }
        }
    }

    function is_dirty() {
        if (serialize($this->properties) != serialize($this->unmodified_properties))
            return true;
        else
            return false;
    }

    /**
     * Fetches a list of dirty (modified) fields.
     *
     * Will return a list of properties that has been changed, since the object
     * was fetched. This only works with properties that can be read as strings
     * ie. not arrays and objects, since (string) $object will always just
     * return object and same with arrays.
     *
     *
     * @return array
     */
    function dirty_fields() {
        $props = $this->properties;
        $uprops = $this->unmodified_properties;

        if (!is_array($uprops)) $uprops = Array();

        if (is_array($this->associations)) {
            foreach (array_keys($this->associations) as $name) {
			if(isset($props[$name])){
                    unset($props[$name]);
                }
		if(isset($uprops[$name])){
                    unset($uprops[$name]);
                }
            }
        }

        return array_diff_assoc($props, $uprops);
    }


    function is_new() {
        if ($this->properties[$this->find_primary()])
            return false;
        else
            return true;
    }

    /*
     * TODO: Review this function
     */
    protected function validate_data($name, $value) {
        if (is_null($value)) {
            return NULL;
        }
        if (!array_key_exists($name, $this->meta)) {
            //We dont know about the value, and it is most likely the value of an association
            return $value;
        }
        switch ($this->meta[$name]['type']) {
            case 'blob':
            case 'text':
            case 'date':
            case 'string':
                return $value;
                break;
            case 'integer':
                    $testValue = ltrim($value,"0");
                    if ($testValue=="" && $value==0) $testValue=0;
                    $intval = filter_var($testValue,FILTER_VALIDATE_INT);
                    if ($intval !== FALSE) {
                        return $value;
                    }
                break;
            case 'double':
            case 'float':
		$doubleval = filter_var($value,FILTER_VALIDATE_FLOAT);
                if ($doubleval !== FALSE) {
                    return $value;
                }
                break;
            default:
                return $value;
                break;
        }
        throw new Exception("Invalid value for $name: '$value'");
    }

    function getNumberOfAffectedRows() {
        return $this->affected_rows;
    }

	function insert(){
		$this->validate(true);

        $sql_fields = array();
        $sql_values = array();

        foreach ($this->find_timestamps() as $timestamp) {
            if ($this->unmodified_properties[$timestamp] == $this->properties[$timestamp]) {
                $this->$timestamp = "now()";
            }
        }
		
        foreach ($this->properties as $key => $value) {
            if (!$this->hasColumn($key)) continue;

            if (($this->meta[$key]['required'] == false || $this->meta[$key]['extra'] == 'auto_increment' || $this->meta[$key]['default'] != '') && ( isset($this->properties[$key]) === false || $this->properties[$key] === "NULL")) {
                continue;
            } elseif (is_array($value) && !isset($this->meta[$key])) {
                continue;
            } elseif ($this->properties[$key] === null || $this->properties[$key] === "NULL") {
                throw new Exception("$key is required  for table . {$this->table_name}!");
            }

            if (!isset($this->meta[$key])) {
                continue;
            }
            array_push($sql_fields, SQLSyntaxor::addGnyfToKey($key, $this->getDatabaseDriver()));
            array_push($sql_values, $this->prepare_property($key));
        }
        $fields = join(", ", $sql_fields);
        $values = join(", ", $sql_values);
        $optionsArray = array(
            "TABLE" => $this->table_name,
            "FIELDS" => $fields,
            "VALUES" => $values
        );
		$db = Database::connect($this->database);
		$sql = SQLSyntaxor::getCreateSQL($optionsArray, $this->getDatabaseDriver());
		$result = $this->query($sql, $db);

		if(!$result){
            $errorInfo = $db->errorInfo();
            throw new Exception("Failed to create the row in database - " . $errorInfo[2] . " - $sql");
		}

	     $this->flush_cache(); 
		return $result;
	}

    function create($include_assoc = true) {
		$this->execute_hooks("before_create");
		$result = $this->insert();
        try {
            $pri = $this->find_primary();
        } catch (Exception $e) {
            $pri = null;
        }
        if ($pri) {
            if ($this->$pri === null) {
				$db = Database::connect($this->database);
				$this->$pri = $this->getLastInsertID($db);
            }
            $this->find($this->$pri);
        }
        if ($include_assoc) {
            $this->update_assoc();
        }
		$this->execute_hooks("after_create");
    }

    function update($include_assoc = true, $guess = false, $extra_cond = '') {
		$this->execute_hooks("before_update");
        if (!$this->is_dirty()) {
            return true;
        }

        $this->validate(true);

        
        $sql_values = array();
        foreach ($this->find_timestamps() as $key => $timestamp) {
            if ($this->unmodified_properties[$timestamp] == $this->properties[$timestamp] && $key == "update") {
                $this->$timestamp = "now()";
            }
        }
        foreach ($this->dirty_fields() as $key => $value) {
			if(!array_key_exists($key,$this->meta)) continue;
            if ($this->meta[$key]['required'] == true && ($value === null || $value === "NULL")) {
                throw new Exception("$key is required for table . {$this->table_name}");
            }
            if (false !== $this->prepare_property($key)) {
				array_push($sql_values, SQLSyntaxor::addGnyfToKey($key, $this->getDatabaseDriver()) . " = " . $this->prepare_property($key));
            }
        }
        if ($sql_values) {
            $values = join(", ", $sql_values);
            if ($this->find_primaries()) {
                $where = array();
                foreach ($this->find_primaries() as $primary) {
                    if (!isset($this->properties[$primary]) || !isset($this->unmodified_properties[$primary])) {
                        throw new Exception("Primary field {$primary} cannot be empty on UPDATE");
                    }
                    $where[] = SQLSyntaxor::addGnyfToKey($primary, $this->getDatabaseDriver()) . ' = ' . $this->prepare_property($primary, $this->unmodified_properties[$primary]);
                }
                $where = join(" and ", $where);
            } elseif (count($this->unmodified_properties) > 0 && $guess) {
                $sql_values = array();
                foreach ($this->unmodified_properties as $key => $value) {
                    if ($value) {
						array_push($sql_values, SQLSyntaxor::addGnyfToKey($key, $this->getDatabaseDriver()) . " = " . $this->prepare_property($key));
                    }
                }
                $where = join(' and ', $sql_values);
            } else {
                throw new Exception("Cannot update object - primary key or unmodified_properties unknown.");
            }
            if ($extra_cond != '')
                $where .= ' ' . $extra_cond;
            $optionsArray = array("TABLE" => $this->table_name, "WHERE" => $where, "VALUES" => $values);
            $sql = SQLSyntaxor::getUpdateSQL($optionsArray, $this->getDatabaseDriver());
			$db = Database::connect($this->database);
            $result = $this->query($sql, $db);
            if (!$result) {
                throw new Exception(print_r($db->errorInfo(), 1) . $sql . "\n");
            }
            $this->affected_rows = $result->rowCount();
	    if($this->affected_rows) $this->flush_cache(); 
        }
        if ($include_assoc) {
            $this->update_assoc();
        }
        $this->unmodified_properties = $this->properties;
		$this->execute_hooks("after_update");
    }

    function flush_cache(){
	    if ( isset($this->_cache) && $this->_cache ){
		$cKeys = mCached::get($this->cache_prepend.':list:'.$this->tablename);
		if ( $cKeys ){
			$all_cache_keys = unserialize($cKeys);
			foreach ( $all_cache_keys as $cache_key ){
				mCached::delete($cache_key);
			}
		}
	    }
    }

    function save($include_assoc = true) {
		$this->execute_hooks("before_save");
        $primary = $this->find_primaries();
        if (count($primary)) $primary=$primary[0];
        else $primary=null;

        if (($primary && isset($this->properties[$primary])) || $this->unmodified_properties) {
            $returnval = $this->update($include_assoc);
        }
        else
            $returnval = $this->create($include_assoc);
		$this->execute_hooks("after_save");
		return $returnval;
    }

    function destroy($guess = false) {
		$this->execute_hooks("before_destroy");
        if ($this->find_primaries()) {
            $where = array();
            foreach ($this->find_primaries() as $primary) {
                if (!isset($this->properties[$primary])) {
                    throw new Exception("Primary field {$primary} cannot be empty on DELETE");
                }
                $where[] = SQLSyntaxor::addGnyfToKey($primary, $this->getDatabaseDriver()) . ' = ' . $this->prepare_property($primary);
            }
            $where = join(" and ", $where);
        } elseif ($guess) {
            $sql_values = array();
            foreach ($this->unmodified_properties as $key => $value) {
                array_push($sql_values, SQLSyntaxor::addGnyfToKey($key, $this->getDatabaseDriver()) . "=" . $this->prepare_property($key, $value));
            }
            $where = join(" and ", $sql_values);
        } else {
            throw new Exception('Need primary key or $guess in order to destroy');
        }
        $optionsArray = array(
            "TABLE" => $this->table_name,
            "WHERE" => $where
        );
        $sql = SQLSyntaxor::getDestroySQL($optionsArray, $this->getDatabaseDriver());
		$db = Database::connect($this->database);
		$result = $this->query($sql,$db);
        if (!$result) {
            throw new Exception(print_r($db->errorInfo(), 1) . $sql . "\n");
        }
        $this->affected_rows = $result->rowCount();
		$this->execute_hooks("after_destroy");
    }

    function update_assoc() {
        
        if (!is_array($this->associations))
            return;
        foreach ($this->associations as $name => $assoc) {
            $assoc_val = isset($this->properties[$name]) ? $this->properties[$name] : null;
            if ($assoc_val !== null) {
                /*
                 * First we update the associated objects. All objects that are loaded will be saved.
                 * If the association is an array meaning: has_many or has_and_belongs_to_many
                 * it will loop through and save changes to each object.
                 */
                if (is_array($assoc_val)) {
                    $tmparr = array();
                    foreach ($assoc_val as $obj) {
                        if ($assoc['ass_type'] == 'has_many') {
                            try {
                                $obj->$assoc['class_property'] = $this->$assoc['local_property'];
                            } catch (Exception $e) {
                                var_dump($e);
                            }
                        }
                        $obj->save();
                        $tmparr[] = $obj;
                    }
                    $this->properties[$name] = $tmparr;
                } else {
                    $assoc_val->save();
                }// Update done

                /*
                 * If its a has_and_belongs_to_many we have to update the association table also
                 */
                if ($assoc['ass_type'] == 'has_and_belongs_to_many') {
                    /*
                     * Compare arrays to find associations that needs to be deleted or added
                     */
                    $objectsToLink = array_udiff((array)$this->properties[$name], (array)$this->unmodified_properties[$name], array("ActiveRecord", "cmpFunc"));
                    $objectsToUnlink = array_udiff((array)$this->unmodified_properties[$name], (array)$this->properties[$name], array("ActiveRecord", "cmpFunc"));

                    /*
                     * Objects to be deleted can easily be packed into a single sql statement.
                     */
					$gnyffed_local_property = SQLSyntaxor::addGnyfToKey($assoc['join_local_property'], $this->getDatabaseDriver());
					$gnyffed_class_property = SQLSyntaxor::addGnyfToKey($assoc['join_class_property'], $this->getDatabaseDriver());
                    if ($objectsToUnlink) {
                        $classProps = array();
                        foreach ($objectsToUnlink as $obj) {
                            $classProps[] = $obj->prepare_property($assoc['class_property']);
                        }
                        $optionsArray = array(
                            "TABLE" => $assoc['join_table'],
                            "WHERE" => $gnyffed_local_property . " = " .
                            $this->prepare_property($assoc['local_property'], $this->unmodified_properties[$assoc['local_property']]) .
                            " AND " . $gnyffed_class_property . " IN(" . join(",", $classProps) . ");"
                        );
                        $unlinkSql = SQLSyntaxor::getDestroySQL($optionsArray, $this->getDatabaseDriver());
                    }
                    /*
                     * Objects to link needs to be set as many different statements
                     * TODO: Optimize the insert sequence.
                     */
                    if ($objectsToLink) {
                        $classProps = array();
                        $linkSqls = array();
                        foreach ($objectsToLink as $obj) {
                            $classProps[] = $obj->prepare_property($assoc['class_property']);
                        }

                        foreach ($classProps as $classProp) {
                            $optionsArray = array(
                                "TABLE" => $assoc['join_table'],
                                "FIELDS" => $gnyffed_local_property . ", " . $gnyffed_class_property,
                                "VALUES" => $this->prepare_property($assoc['local_property']) . ", " . $classProp
                            );
							$linkSqls[] = SQLSyntaxor::getCreateSQL($optionsArray, $this->getDatabaseDriver());
                        }
                    }

                    /*
                     * Execute all the SQL.
                     */
			$db = Database::connect($this->database);
			if(isset($unlinkSql)){
				$this->query($unlinkSql, $db);
			}
			if(isset($linkSqls)){
				foreach($linkSqls as $sql){
					$this->query($sql,$db);
				}
                    }
                }
            }
        }
    }

    /*
     * a more robust compare function used to see if associations have changed.
     */

    function cmpFunc($a, $b) {
        if (serialize($a) === serialize($b))
            return 0;
        return serialize($a) > serialize($b) ? -1 : 1;
    }

	protected function query($sql, $db){
		if(isset($this->debug) && $this->debug)
			error_log($sql);
		return $db->query($sql);
	}

    function getLastInsertID($db) {
        $id = $db->lastInsertId();
        if (!$id) {
            $sql = SQLSyntaxor::getLastInsertIdSQL($this->getDatabaseDriver());
            if ($sql == "") {
                return false;
            }
	    $id = $this->query($sql, $db)->fetchColumn();
        }
        return $id;
    }

    public function validate($throw=false) {
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (substr($method, 0, 11) == "__validate_") {
                if (true !== ($msg = call_user_func(Array($this, $method)))) {
                    if ($throw) {
                        throw new ActiveRecordValidationException($msg);
                    } else {
                        return $msg;
                    }
                }
            }
        }

        return true;
    }

	public static function add_generic_hook($hookpoint, $function){
		self::validate_hookpoint($hookpoint);
		self::$generic_hooks[$hookpoint][] = $function;
	}

	public function add_model_hook($hookpoint, $function){
		self::validate_hookpoint($hookpoint);
		$this->specific_model_hooks[$hookpoint][] = $function;
	}

	private static function validate_hookpoint($hookpoint){
		if(!in_array($hookpoint, array(
			'before_find',
			'after_find',
			'before_update',
			'after_update',
			'before_create',
			'after_create',
			'before_save',
			'after_save'))){
			throw new ActiveRecordValidationException("Unknown hookpoint: $hookpoint");
		}
		return true;
	}

	public function execute_hooks($hookpoint, $extra_params = array()){
		if(isset(self::$generic_hooks[$hookpoint])){
			foreach(self::$generic_hooks[$hookpoint] as $function){
				call_user_func($function, $this, $extra_params);
			}
		}

		if(isset($this->specific_model_hooks[$hookpoint])){
			foreach($this->specific_model_hooks[$hookpoint] as $function){
				call_user_func($function, $this, $extra_params);
			}
		}
	}

	public function getDatabaseDriver(){
		return ActiveRecordDatabase::getDatabaseDriver($this->database);
	}

}
