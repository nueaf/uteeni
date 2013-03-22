<?php
require_once dirname(__FILE__) . "/modelLoader.php";

function load_active_record_model($className) {
	$file = dirname(__FILE__) . "/../models/" . strtolower($className) . ".php";
	if (file_exists($file)) {
		return include_once($file);
		return true;
	}
	
	return false;
}

spl_autoload_register("load_active_record_model");
