<?php
class SQLSyntaxor{
	
	static function getSelectSQL($options = array(), $driver = "mysql"){
		$driver = $driver ? $driver : "mysql"; 
		$options['SELECT'] = (isset($options['SELECT']) && $options['SELECT']) ? $options['SELECT'] : '*';
		switch(strtoupper($driver)){
			case 'MYSQL':
				$sql = "SELECT [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+] [+ORDERFIELD+] [+ORDERTYPE+] [+LIMIT+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
						case 'ORDERFIELD':
							$option = "ORDER BY $option";
							break;
						case 'ORDERTYPE':
							if(!array_key_exists("ORDERFIELD", $options))
								continue;
							break;
						case 'LIMIT':
							$option = "LIMIT " . (array_key_exists('OFFSET', $options) ? "$options[OFFSET], " : "") . $option;
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'OCI':
                                $limit = 0;
				$offset = 0;
				$sql = "SELECT [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+] [+LIMIT+] [+ORDERFIELD+] [+ORDERTYPE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
						case 'ORDERFIELD':
							$option = "ORDER BY $option";
							break;
						case 'ORDERTYPE':
							if(!array_key_exists("ORDERFIELD", $options))
								continue;
							break;
						case 'LIMIT':
							if( isset($options['OFFSET']) && $options['OFFSET'] ){
								$limit = $option+$options['OFFSET'];
								$offset = $options['OFFSET'];
								continue 2;
							} else {
								$option = (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM <= '".$option."' ";
							}
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				if($offset){
					$where_limit 	= (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM < '".$limit."'";
					$where_offset	= (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM < '".$offset."'";
					$sql = $sql . $where_limit . ' minus ' . $sql . $where_offset;
				}
				break;
                        case 'DBLIB':
                                $limit = 0;
				$offset = 0;
				$sql = "SELECT [+LIMIT+] [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+]  [+ORDERFIELD+] [+ORDERTYPE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
						case 'ORDERFIELD':
							$option = "ORDER BY $option";
							break;
						case 'ORDERTYPE':
							if(!array_key_exists("ORDERFIELD", $options))
								continue;
							break;
						case 'LIMIT':
							$option = "TOP $option";
							break;
                                                case 'OFFSET':
                                                        if($option){
                                                            trigger_error("Offset not supported for MSSQL");
                                                        }
                                                        break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
                            break;
						
		}
		$sql = preg_replace("/\[\+[A-Z]*\+\]/",  "", $sql);
		return $sql;
	}
	
	static function getUpdateSQL($options = array(), $driver = "mysql"){
		$driver = $driver ? $driver : "mysql"; 
		switch(strtoupper($driver)){
			case 'MYSQL':
				$sql = "UPDATE [+TABLE+] SET [+VALUES+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'OCI':
				$sql = "UPDATE [+TABLE+] SET [+VALUES+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'DBLIB':
				$sql = "UPDATE [+TABLE+] SET [+VALUES+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
		}
		return $sql;		
	}


	static function getCreateSQL($options = array(), $driver = "mysql"){
		/*
		 * TODO: Allow for inserting multiple rows in one statement. 
		 */
		$driver = $driver ? $driver : "mysql"; 
		switch(strtoupper($driver)){
			case 'MYSQL':
				$sql = "INSERT INTO [+TABLE+] ([+FIELDS+]) VALUES([+VALUES+])";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'OCI':
				$sql = "INSERT INTO [+TABLE+] ([+FIELDS+]) VALUES([+VALUES+])";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
                        case 'DBLIB':
				$sql = "INSERT INTO [+TABLE+] ([+FIELDS+]) VALUES([+VALUES+]);";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
		}
		return $sql;			
	}

	static function getDestroySQL($options = array(), $driver = "mysql"){
		$driver = $driver ? $driver : "mysql"; 
		switch(strtoupper($driver)){
			case 'MYSQL':
				$sql = "DELETE FROM [+TABLE+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'OCI':
				$sql = "DELETE FROM [+TABLE+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;
			case 'DBLIB':
				$sql = "DELETE FROM [+TABLE+] [+WHERE+]";
				foreach($options as $key => $option){
					switch ($key){
						case 'WHERE':
							$option = "WHERE $option";
							break;
					}
					$sql = str_replace("[+$key+]", $option, $sql);
				}
				break;

		}
		return $sql;
	}	

	static function getLastInsertIdSQL($driver = "mysql"){
		$driver = $driver ? $driver : "mysql"; 
		switch(strtoupper($driver)){
                    case 'DBLIB':
                        $sql = "SELECT SCOPE_IDENTITY() AS mixLastId";
                        break;
                }  
                return $sql;
        }

	static function addGnyfToKey($key, $driver = "mysql"){
		switch(strtoupper($driver)){
			case 'MYSQL':
				return "`$key`";
				break;
			case 'DBLIB':
			case 'OCI':
				return "\"$key\"";
				break;
		}
	}
}



?>
