<?php

/**
 * Basic requires for active record
 */
require_once dirname(__FILE__) . "/activerecord.php";
require_once dirname(__FILE__) . "/database.php";

/**
 * ModelLoader - for instantiating of model classes
 *
 */
class ModelLoader {
	
	/**
	 * Load function for data/model classes
	 * ex. $theBox = ModelLoader::Load('Multibox');
	 *
	 * @param string $className
	 * @return object
	 */
	public static function Load($className){
		try {
			include_once dirname(__FILE__) . "/../models/" . strtolower($className) . ".php";
			return new $className;
		} catch (Exception $e){
			throw $e;
		}

	}
	
}

?>
