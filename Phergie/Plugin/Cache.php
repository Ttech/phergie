<?php
/**
 * Phergie
 *
 * PHP version 5
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://phergie.org/license
 *
 * @category  Phergie
 * @package   Phergie_Plugin_Cache
 * @author    Phergie Development Team <team@phergie.org>
 * @copyright 2008-2010 Phergie Development Team (http://phergie.org)
 * @license   http://phergie.org/license New BSD License
 * @link      http://pear.phergie.org/package/Phergie_Plugin_Cache
 */

/**
 * Implements a generic cache to be used by other plugins.
 *
 * @category Phergie
 * @package  Phergie_Plugin_Cache
 * @author   Phergie Development Team <team@phergie.org>
 * @license  http://phergie.org/license New BSD License
 * @link     http://pear.phergie.org/package/Phergie_Plugin_Cache
 */
class Phergie_Plugin_Cache extends Phergie_Plugin_Abstract
{
    /**
     * Key-value data storage for the cache
     *
     * @var array
     */
    protected $cache = array();


	protected $strategy = 'array';

    /**
     * Db storage instance for storing the cache
     *
     * @var Phergie_Plugin_Db
     */
    protected $db;

    /**
     * Namespace for db storage
     *
     * False if no namespace is given, so we can't use a backend
     *
     * @var string|bool
     */
    protected $namespace = null;

	protected $namespaceExistsSQL;
 	protected $namespaceSetSQL;
	protected $updateKey;
	protected $dbInsertData;
	protected $dbFetch;

    // smarter way to load everything needed (once and handles namespace)

    public function onLoad()
    {
        $plugins = $this->getPluginHandler();
        $plugins->getPlugin('Cron'); // kinda need this. Or will
        $plugins->getPlugin('Db');

        // Load the Database some database
        $this->db = $this->plugins->getPlugin('Db')->init(
            'Cache/',
            'cache.db',
            'cache.sql'
        );
        $this->buildDbSQL();
    }


	public function setNamespace($namespace){
		$this->namespace = $namespace;	
	}

	protected function buildDbSQL(){
		$this->keyExists = $db->prepare('select count(key) 
from phergie_cache where namespace=:namespace and key=:key');
		$this->namespaceExists = $db->prepare('select count(namespace) 
from phergie_cache where namespace=:namespace');
		$this->updateKey = $this->db->prepare('update phergie_cache 
set value=:value, expiration=:expire where namespace=:namespace');
		$this->dbInsertData = $this->db->prepare('insert into phergie_cache
 (namespace, key, value, expiration) VALUES (:namespace, :key, :value, :expiration)');
		$this->dbFetch = $this->db->prepare('SELECT value FROM phergie_cache
            WHERE namespace=:namespace AND key=:key');
	}

	// This should give you the general check statements
	// this should work with some minor tweaking. 
	/*
		If it fails:
		The query should always return either 0 or a number 1 
		or the second query > 1
	*/
	public function setStorageStrategy($type){
		$this->strategy = strval($type);
	}
	public function db_key_exists($key){
		$parameters = array(
			':namespace' => $this->namespace, // since null it will skip
			':key' => trim($key)
		);
		$keyExists = $this->keyExists->execute($parameters);
		return (bool) $keyExists->fetch();
	}
	public function db_namespace_exists($key){
		$parameters = array(
			':namespace' = $this->namespace, // since null it will skip
		);
		$keyExists = $this->namespaceExists->execute($parameters);
		return (bool) $keyExists->fetch();
	}
	protected db_simpleStore($key,$data,$ttl,$overwrite = true){
		$values = array(
			':namespace'  => $this->namespace,
			':key'        => $key,
			':value'      => $value,
			':expiration' => $expiration,
		);

		if ($this->issetBackend($key) && $overwrite === true) {
			$prepare = $this->db->prepare($queryUpdate);
		} else {
			$prepare = $this->db->prepare($queryInsert);
		}
		$prepare->execute($values);		
	}

     */
    public function db_simple_fetch($key)
    {

        $prepare = $this->db->prepare($query);
        $prepare->execute(array(
            ':key'       => $key,
            ':namespace' => $this->namespace,
            ':time'      => time(),
        ));

        if ($prepare->columnCount() === 1) {
            $results = $prepare->fetch();
            return $results[0];
        }

        return false;
    }
	
// provides compatibility for each standard function 
	public function fetch($key){
		switch($this->strategy){
			case 'array':
				return $this->array_fetch($key);
			break;
			case 'db_basic':
				return $this->db_basic_fetch($key);
			break;
			case 'db_complex':
				return "your function goes here";
			break		
		}
	}

    /**
     * Stores a value in the cache.
     *
     * @param string   $key       Key to associate with the value
     * @param mixed    $data      Data to be stored
     * @param int|null $ttl       Time to live in seconds or NULL for forever
     * @param bool     $overwrite TRUE to overwrite any existing value
     *        associated with the specified key
     *
     * @return bool
     */
    public function array_store($key, $data, $ttl = 3600, $overwrite = true)
    {
        if (!$overwrite && isset($this->cache[$key])) {
            return false;
        }

        if ($ttl) {
            $expires = time()+$ttl;
        } else {
            $expires = null;
        }

        $this->cache[$key] = array('data' => $data, 'expires' => $expires);
        return true;

    }

    /**
     * Fetches a previously stored value.
     *
     * @param string $key Key associated with the value
     *
     * @return mixed Stored value or FALSE if no value or an expired value
     *         is associated with the specified key
     */
    public function array_fetch($key)
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        $item = $this->cache[$key];
        if (!is_null($item['expires']) && $item['expires'] < time()) {
            $this->expire($key);
            return false;
        }

        return $item['data'];
    }

    /**
     * Expires a value that has exceeded its time to live.
     *
     * @param string $key Key associated with the value to expire
     *
     * @return bool
     */
    protected function array_expire($key)
    {
        if (!isset($this->cache[$key])) {
            return false;
        }
        unset($this->cache[$key]);
        return true;
    }
}
