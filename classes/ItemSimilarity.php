<?php
/**
 * Created by JetBrains PhpStorm.
 * User: bik
 * Date: 4/25/13
 * Time: 10:14 PM
 * To change this template use File | Settings | File Templates.
 */
class ItemSimilarity {
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

	/**
	 * @var int
	 */
	protected $itemid = null;

	const KEY = 'isim:';


	/**
	 */
	public function __construct() {
//		$this->memkey = sprintf(static::KEY, $itemid);
	}

	/**
	 * push item to user history
	 * @param $item1
	 * @param $item2
	 * @param float $sim
	 */
	public function set($item1, $item2, $sim) {
		$redis = RedisHandler::getConnection();
		$memkey1 = static::KEY . $item1;
		$is_new = $redis->zAdd($memkey1, $sim, $item2);

		if ($is_new && $redis->zcard($memkey1) > $this->number_of_items) {
			$redis->zremrangebyrank($memkey1, 0, -($this->number_of_items + 1));
		}
		$redis->expire($memkey1, $this->ttl);

		$memkey2 = static::KEY . $item2;
		$is_new = $redis->zAdd($memkey2, $sim, $item1);

		if ($is_new && $redis->zcard($memkey2) > $this->number_of_items) {
			$redis->zremrangebyrank($memkey2, 0, -($this->number_of_items + 1));
		}

	}


	/**
	 * @param $itemid
	 * @param int $limit maximum number of entries to fetch
	 * @return array id of the items
	 */
	public function getSimilar($itemid, $limit = 100) {
		$redis = RedisHandler::getConnection();
		$memkey = static::KEY . $itemid;
		return $redis->zRevRange($memkey, 0, $limit - 1);
	}
}