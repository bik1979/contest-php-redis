<?php

/**
 * Created by JetBrains PhpStorm.
 * User: vr
 * Date: 19/04/13
 * Time: 11:14
 * To change this template use File | Settings | File Templates.
 */
class PopularItemsList {
	/**
	 * @var int How big do we allow the list to get
	 */
	protected $number_of_items = 12;

	/**
	 * @var int how many seconds should the list live, since the last update
	 */
	protected $ttl = 86400;

	protected $max_slots = 4;

	/**
	 * slot size in minutes
	 * @var int
	 */
	protected $slot_size = 60;

	protected $limit = 250;

	/**
	 * memory key used for querying this list
	 * @var string
	 */
	protected $memkey = null;

	const KEY = 'pi:%s';


	/**
	 * @param string $domainid
	 */
	public function __construct($domainid) {
		$this->memkey = sprintf(static::KEY, $domainid);
	}

	/**
	 * push item
	 * @param string $id itemid
	 */
	public function push($id) {
		$redis = RedisHandler::getConnection();
		$slot_id = ceil(time() / $this->slot_size / 60);
		$slot_key = $this->memkey . ':' . $slot_id;

		// register impression
		$item_count = $redis->zincrby($slot_key, 1, $id);
		//TODO check atomically if its the first item now there could be dirty reads
		$slot_items_count = $redis->zcard($slot_key);
		if ($slot_items_count == 1 && $item_count == 1) {
			//this is first item inserted in this slot
			$redis->expire($slot_key, $this->max_slots * $this->slot_size * 60 * 2); //hard ttl a bit longer
			//limit the previous block to the top-k elements
			if ($this->limit > 0) {
				$previous_slot = $slot_id - 1;
				$previous_slot_key = $this->memkey . ':' . $previous_slot;
				$redis->zremrangebyrank($previous_slot_key, 0, -($this->limit + 1));

				//aggregate last slot to total sum, and delete it
				$sum_slot_key = $this->memkey . ':t';
				$last_slot = ($slot_id - $this->max_slots);
				$last_slot_key = $this->memkey . ':' . $last_slot;
				//decay 66% cumulated total before adding last slot
				$redis->zUnion($sum_slot_key, array($sum_slot_key, $last_slot_key), array(0.666, 1));
				$redis->zRemRangeByScore($sum_slot_key, '-inf', 0.01);
				$redis->zremrangebyrank($sum_slot_key, 0, -(3 * $this->limit + 1));
				$redis->expire($sum_slot_key, $this->max_slots * $this->slot_size * 60 * 3); //hard ttl a bit longer

				$redis->del($last_slot_key);
			}

		}
	}


	/**
	 * get items from the list
	 * @param int $limit
	 * @param array $weights
	 * @param float $sum_weight
	 * @return array
	 */
	public function get($limit = 100, array $weights = array(), $sum_weight = 0.05) {
		$redis = RedisHandler::getConnection();
		if (empty($weights)) {
			$weights = array(1, 0.75, 0.25);
		}
		$current_slot = ceil(time() / $this->slot_size / 60);
//        $start_slot = ($current_slot -  $this->max_slots );
		$slot_keys = array();
		for ($i = 0; $i < $this->max_slots; $i++) {
			$slot_id = $current_slot - $i;
			$slot_keys[] = $this->memkey . ':' . $slot_id;
		}
		if ($sum_weight > 0) {
			$slot_keys[] = $this->memkey . ':t';
			$weights[] = $sum_weight;
		}
		$tmp_key = $this->memkey . ':tmp:' . mt_rand(0, 99999);
		$redis->zUnion($tmp_key, $slot_keys, $weights);
		$list = $redis->zRevRange($tmp_key, 0, $limit - 1);
		$redis->del($tmp_key);
		return $list;
	}

}
