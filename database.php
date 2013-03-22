<?

class Database{

	static $conn = null;
	static $host = null;
	static $db 	 = null;
	static $user = null;
	static $pass = null;
	static $port = null;

    /**
     *
     * @param string $driver
     * @return PDO
     */
	static function connect($driver = "mysql"){
		$driver = $driver ? $driver : "mysql";
		$driver = strtoupper($driver) . "Database";
				self::$host ? $driver::$host 	= self::$host 	: "";
				self::$db 	? $driver::$db 		= self::$db 	: "";
				self::$user ? $driver::$user 	= self::$user 	: "";
				self::$pass ? $driver::$pass 	= self::$pass 	: "";
				self::$port ? $driver::$port 	= self::$port 	: "";
				return $driver::connect();
				break;
	}
}

class ActiveRecordDatabase {
	static $dbconfig = null;

	public static function parsedbini($file_path, $strict_names=false){
			self::$dbconfig = parse_ini_file($file_path, true);
			if (!$strict_names) {
	            foreach (array_keys(self::$dbconfig) as $db) {
    	                self::$dbconfig[strtolower($db)] = self::$dbconfig[$db];
        	    }
			}
        }

	public static function load($name) {
		if (self::$dbconfig==null) {
                    self::parsedbini($_SERVER["dbini_dir"].$_SERVER["dbini_file"]);
		}

		if(!array_key_exists($name, self::$dbconfig)){
			$name = strtolower($name);
		}
		
		if (array_key_exists($name, self::$dbconfig)) {
			$dbconf = self::$dbconfig[$name];
                        $dbconf['DBNAME'] = $name;
                        if (!array_key_exists("dsn", $dbconf)) {
                        	$charset = isset($dbconf['charset']) && $dbconf['charset'] ? ".';charset={$dbconf['charset']}'" : '';
							$dbconf['driver_options'] = 'array()';
                            switch($dbconf["type"]){
								case 'mysql':
									$dbconf['driver_options'] = isset($dbconf['charset']) && $dbconf['charset'] ? 'array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ' . $dbconf['charset'] .'")' : 'array()';
                                    $dbconf['dsn'] = 'self::$type.":host=".self::$host.(self::$port?":".self::$port:"").";dbname=".self::$db';
									break;
                                case 'oci':
                                    $dbconf['dsn'] = 'self::$type.":dbname=//".self::$host.(self::$port?":".self::$port:"")."/".self::$db'.$charset;
                                    break;
                                case 'tnsnames':
                                    $dbconf['dsn'] = '"oci:dbname=".self::$db';
                                    break;
                                case 'dblib':
                                    $dbconf['dsn'] = 'self::$type.":host=".self::$host.(self::$port?":".self::$port:"").";dbname=".self::$db'.$charset;
                                    break;
                                default:
                                    throw new exception("Unknown databasetype {$dbconf['type']}");
                                    break;
                            }
                        }
                        $dbconf['port'] = array_key_exists("port", $dbconf) && $dbconf["port"]?$dbconf["port"]:"null";
                        $clsStr = file_get_contents(dirname(__FILE__) . "/database_template.txt");

                        foreach($dbconf as $conf_key => $conf){
                            $clsStr = str_replace("#$conf_key#", $conf, $clsStr);
                        }
			eval($clsStr);
		}
	}

	public static function getDatabaseDriver($name){
		if (self::$dbconfig==null) {
                    self::parsedbini($_SERVER["dbini_dir"].$_SERVER["dbini_file"]);
		}
		$name .= "Database";
		if(!array_key_exists($name, self::$dbconfig)){
			$name = strtolower($name);
		}
		$dbconf = self::$dbconfig[$name];
		return $dbconf['type'];
	}
}

spl_autoload_register("ActiveRecordDatabase::load");
