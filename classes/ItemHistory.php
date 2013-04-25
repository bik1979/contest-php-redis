<?php

/**
 * Created by JetBrains PhpStorm.
 * User: vr
 * Date: 19/04/13
 * Time: 11:14
 * To change this template use File | Settings | File Templates.
 */
class ItemHistory {
	/**
	 * @var int How big do we allow the list to get
	 */
	protected $number_of_users = 250;

	/**AbstractList
	 * @var int how many seconds should the list live
	 */
	protected $ttl = 86400;

	/**
	 * memory key used for querying this list
	 * @var string
	 */
	protected $memkey = null;

	const KEY = 'ih:%s';


	/**
	 * @param string $itemid
	 */
	public function __construct($itemid) {
		$this->memkey = sprintf(static::KEY, $itemid);
	}

	/**
	 * push userid to item history
	 * @param string $id userid
	 * @return int
	 */
	public function push($id) {
		$redis = RedisHandler::getConnection();

		$size = $redis->lpush($this->memkey, $id);
		if ($size > 1) {
			file_put_contents('plista.log', "\n" . date('c') . " {$this->memkey} size:$size \n", FILE_APPEND);
		}
		if ($size > $this->number_of_users) {
			$redis->ltrim($this->memkey, 0, $this->number_of_users - 51);
		}

		$redis->expire($this->memkey, $this->ttl);

		return $size;
	}


	/**
	 * @param int $limit maximum number of entries to fetch
	 * @return array users that have seen this item
	 */
	public function get($limit = 100) {
		$redis = RedisHandler::getConnection();

		$users = $redis->lrange($this->memkey, 0, $limit - 1);

		return array_unique($users);
	}

}