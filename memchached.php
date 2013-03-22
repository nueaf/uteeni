<?php
/**
 * Memechache support enabling<br />
 * supports model based memcache suport, thus specific define which table classes that should be used by memcache
 *
 * @author Søren C. Hansen
 *
 */

/**
 * Support class for memcache (static)
 */ 
class mCached {

	/**
	 * if basic setup is done
	 */
	private static $ready = FALSE;

	/**
	 * boolean definition of memcached being available
	 */
	private static $available = FALSE;

	/**
	 * boolean definition of memcached being enabled
	 */
	private static $enabled = FALSE;

	/**
	 * Memcahce time to live
	 */
	private static $ttl = 3600;

	/**
	 * Memcache server address
	 */
	//private static $server = 'iposenc1.vurseb.cfg.euw1.cache.amazonaws.com';
	private static $server = 'localhost';

	/**
	 * memcache container
	 */
	private static $memcache;

	/**
	 * sets up the basics for memcache support
	 */
	private static function setup(){
		// if we already have prepared the basic setup, return
		if ( self::$ready ){
			return;
		}

		// TODO - how do we define if memcache is allowed ?
		self::$enabled = TRUE;

		/**
		 * Check if memcache is supported<br />
		 * if not disable support and mark as unavailable and return
		 */
		if ( !class_exists('Memcached',false)  ){
			self::$available = FALSE;
			self::$enabled = FALSE;
			return;
		}

		// Connect and "singleton" store the memcache instance
		// TODO - how do we get connection info for the memcache server?
		self::$memcache = new Memcached;
		self::$memcache->addServer(self::$server, 11211);

	}

	/**
	 * Checks if memcache is runnable
	 * @return boolean
	 */
	public function runnable(){
		/**
		 * Run setup
		 */
		self::setup();

		if ( !self::$enabled ){
			return false;
		}
		return true;

	}

	/**
	 * Get a memcache value for a key
	 *
	 * @param string key for the value, eg 'uar:modelname:id'
	 * @return mixed value from key
	 */
	public static function get($key){

		// Make sure that memcache is set up
		if ( !self::runnable() ){
			return NULL;
		}

		return self::$memcache->get($key);
	}

	/**
	 * Set a memcache value for a key
	 *
	 * @param string key for the value, eg 'uar:modelname:id'
	 * @param mixed value for the key
	 */
	public static function set($key,$value, $ttl = null){

		// Make sure that memcache is set up
		if ( !self::runnable() ){
			return;
		}

		$ttl ?: self::$ttl;

		/**
		 * Set the value, first tries to replace - if fails - adds it
		 */
		self::$memcache->set($key,$value,$ttl);
	}

	/**
	 * delete a memcache key/value pair
	 *
	 * @param string key for the value, eg 'uar:modelname:id'
	 */
	public static function delete($key){

		// Make sure that memcache is set up
		if ( !self::runnable() ){
			return;
		}

		/**
		 * Set the value, first tries to replace - if fails - adds it
		 */
		self::$memcache->delete($key);
	}

	public static function flush(){
		// Make sure that memcache is set up
		if ( !self::runnable() ){
			return;
		}

		self::$memcache->flush();
	}
}
