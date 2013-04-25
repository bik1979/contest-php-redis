<?php

/**
 * Created by JetBrains PhpStorm.
 * User: vr
 * Date: 19/04/13
 * Time: 11:14
 * To change this template use File | Settings | File Templates.
 */
class UserHistory {
	/**
	 * @var int How big do we allow the list to get
	 */
	protected $number_of_items = 100;

	/**AbstractList
	 * @var int how many seconds should the list live
	 */
	protected $ttl = 86400;

	/**
	 * memory key used for querying this list
	 * @var string
	 */
	protected $memkey = null;

	const KEY = 'uh:%s:%s';


	/**
	 * a different list  per domain
	 * @param string $domainid
	 * @param string $userid
	 */
	public function __construct($domainid, $userid) {
		$this->memkey = sprintf(static::KEY, $userid, $domainid);
	}

	/**
	 * push item to user history
	 * @param string $id itemid
	 */
	public function push($id) {
		$redis = RedisHandler::getConnection();

		$size = $redis->lpush($this->memkey, $id);

		if ($size > 1) {
			file_put_contents('plista.log', "\n" . date('c') . "\n {$this->memkey} size:$size \n", FILE_APPEND);
		}

		if ($size > $this->number_of_items) {
			$redis->ltrim($this->memkey, 0, $this->number_of_items - 1);
		}

		$redis->expire($this->memkey, $this->ttl);
	}


	/**
	 * @param int $limit maximum number of entries to fetch
	 * @return array id of the items
	 */
	public function get($limit = 100) {
		$redis = RedisHandler::getConnection();

		$items = $redis->lrange($this->memkey, 0, $limit - 1);

		return array_unique($items);
	}

}