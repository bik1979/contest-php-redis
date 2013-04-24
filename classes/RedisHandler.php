<?php
/**
 * Created by JetBrains PhpStorm.
 * User: vr
 * Date: 18/04/13
 * Time: 18:18
 * To change this template use File | Settings | File Templates.
 */
class RedisHandler {

	/**
	 * @var \Redis
	 */
	protected static $redis = null;


	/**
	 * @static
	 * @param int $dbi index of database
	 * @return \Redis
	 */
	public static function getConnection($dbi = 0) {
		if (!isset(static::$redis) || is_null(static::$redis)) {
			$redis = new \Redis();
			$host = 'localhost';
			$port = 6379;
			$redis->pconnect($host, $port, 5);
			$redis->select($dbi);
			static::$redis = $redis;
		}
		return static::$redis;
	}


	public static function disconnect() {
		if (!is_null(static::$redis)) {
			static::$redis->close();
			static::$redis = null;
		}
	}

	/**
	 * @static
	 * @param int $dbi index of database
	 */
	public static function flushDB($dbi = 0) {
		$redis = new \Redis();
		$host = 'localhost';
		$port = 6379;
		$redis->pconnect($host, $port, 5);
		$redis->select($dbi);
		$redis->flushDB();
		$redis->close();
	}

}