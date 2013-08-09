<?php

/**
 * This is the reference implementation of a ContestHandler, which does nothing more than store the last items it sees
 * (through impressions), and return them in reverse order.
 */
class ContestHandlerRedis implements ContestHandler {
	// holds the instance, singleton pattern
	private static $instance;

	private function __construct() {
	}

	/**
	 * @return ContestHandlerRedis
	 */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ContestHandlerRedis();
		}

		return self::$instance;
	}

	/**
	 * This method handles received impressions. First it loads the data file, then checks whether the current item is
	 * present in the data. If not, it prepends the new item id and writes the data file back. It then checks whether
	 * it needs to generate a recommendation and if so takes object ids from the front of the data (excluding the new one)
	 * and sends those back to the contest server.
	 */
	public function handleImpression(ContestImpression $impression) {
		$itemid = isset($impression->item->id) ? $impression->item->id : 0;
		$recommendable = isset($impression->item->recommendable) ? $impression->item->recommendable : true;
		$userid = isset($impression->client->id) ? $impression->client->id : 0;
		$domainid = $impression->domain->id;

		$itemPublisherList = new PopularItemsList($domainid);

		$userHistoryList = null;
		$userHistorySeen = null;
		if (!empty($userid)) {
			$userHistoryList = new UserHistory($domainid, $userid);
			$userHistorySeen = new UserHistorySeen($domainid, $userid);
		}

		// check whether a recommendation is expected. if the flag is set to false, the current message is just a training message.
		if ($impression->recommend) {
//			$candidates_list = $this->recommend($itemid, $domainid, $userid, 25);
			$candidates_list = $this->recommend(0, $domainid, 0, 60);
//			shuffle($candidates_list);
			//don't return previously seen items
			$has_current_item = array_search($itemid, $candidates_list);
			if ($has_current_item !== false) {
				unset($candidates_list[$has_current_item]);
			}

			$rank = $this->rescore($candidates_list, $userid, $domainid);
			if (count($rank) < $impression->limit) {
				throw new ContestException('not enough data? ' . count($rank), 500);
			}
			arsort($rank);

			$item_count = 0;
			$result_data = array();
			$userHistorySeen = new UserHistorySeen($domainid, $userid);
			foreach ($rank as $id => $val) {
				$data_object = new stdClass;
				$data_object->id = $id;
				$result_data[] = $data_object;
				$userHistorySeen->push($id);
				$item_count++;
				if ($item_count == $impression->limit) {
					break;
				}
			}
			file_put_contents('plista.log', "\n" . date('c') . " rank: " . print_r($rank, true) . "\n", FILE_APPEND);
			// construct a result message
			$result_object = new stdClass;
			$result_object->items = $result_data;
			$result_object->team = $impression->team;

			$result = ContestMessage::createMessage('result', $result_object);
			// post the result back to the contest server
			$result->postBack();
		}

		if ($recommendable && $itemid > 0) {
			$itemPublisherList->push($itemid);
			$userHistoryList = new UserHistory($domainid, $userid);
			if ($userHistoryList != null) {
				$size = $userHistoryList->push($itemid);
				if (($size % 10) == 0) {
					file_put_contents('plista.log', "\n" . date('c') . " user $userid - $domainid:  history size:$size \n", FILE_APPEND);
				}
				//if we have userid, push it to the item history
				$itemHistory = new ItemHistory($itemid);
				$size = $itemHistory->push($userid);
				//if there's enough new data, try to find similar items
				if (($size % 50) == 0) {
					file_put_contents('plista.log', "\n" . date('c') . " item $itemid: history size:$size \n", FILE_APPEND);
//					$users_list = $itemHistory->get(200);
//					$this->findSimilarItems($itemid, $users_list, $domainid);
				}
			}
		}
	}

	/**
	 * @param $candidates
	 * @param $userid
	 * @param $domainid
	 */
	protected function rescore($candidates, $userid, $domainid) {
		$userHistoryList = null;
		$userHistorySeen = null;
		if (!empty($userid)) {
			$userHistoryList = new UserHistory($domainid, $userid);
			$userHistorySeen = new UserHistorySeen($domainid, $userid);
		}

		$items_read = array();
		if ($userHistoryList != null) {
			$items_read = $userHistoryList->get(25);
		}
		$items_seen = array();
		if ($userHistorySeen != null) {
			$items_seen = $userHistorySeen->get(25);
		}

		$redis = RedisHandler::getConnection();
		$blacklist = $redis->sMembers('bl');
		$clickskey = 'clicks:' . $domainid;
		$clicklist = $redis->zRevRange($clickskey, 0, 500);
		$rank = array();

		foreach ($candidates as $j => $id) {
			if (in_array($id, $blacklist)) {
				file_put_contents('plista.log', "\n" . date('c') . "rescore  $id: invalid item filtered \n", FILE_APPEND);
				continue;
			}
			$score = pow(1.01, -($j + 1));
			//$clicks = $redis->zScore($clickskey, $id);
			if (in_array($id, $clicklist)) {
				file_put_contents('plista.log', "\n" . date('c') . "rescore  $id: +0.5 (click) \n", FILE_APPEND);
				$score += 0.5;
			}

			$is_read = in_array($id, $items_read);
			//skip items already seen recently by the user
			if ($is_read !== false) {
				file_put_contents('plista.log', "\n" . date('c') . "rescore  $id: *0.01 (item read) \n", FILE_APPEND);
				$rank[$id] = 0.01 * $score;
				continue;
			}
			$is_seen = in_array($id, $items_seen);
			//skip items already seen recently by the user
			if ($is_seen !== false) {
				$rank[$id] = 0.1 * $score;
				file_put_contents('plista.log', "\n" . date('c') . "rescore  $id: *0.1 (item seen) \n", FILE_APPEND);
				continue;
			}

			$rank[$id] = $score;
		}
		return $rank;
	}

	/**
	 * @param $itemid
	 * @param $domainid
	 * @param $userid
	 * @param $limit
	 * @return array
	 */
	protected function recommend($itemid, $domainid, $userid, $limit) {
		//list of most popular items
		$itemPublisherList = new PopularItemsList($domainid);
		$popular_items = $itemPublisherList->get($limit);
		if (($itemid == 0 && $userid == 0)) { // || $domainid == 1677) {
			$item_count = count($popular_items);
//			file_put_contents('plista.log', "\n" . date('c') . " 0. recommend $itemid $domainid $userid: $item_count popular items found \n", FILE_APPEND);
			return $popular_items;
		}
		//list of similar items
		$simObj = new ItemSimilarity();
		$similar_items = $simObj->getSimilar($itemid, $domainid, $userid, $limit);

		//return in first place similar and popular items
		$recommendations = $similar_and_popular = array_intersect($popular_items, $similar_items);
		$item_count = count($similar_and_popular);
		file_put_contents('plista.log', "\n" . date('c') . " 1. recommend $itemid $domainid $userid: $item_count similar & popular items found \n", FILE_APPEND);
		if ($item_count >= $limit) {
			if ($item_count > $limit) {
				$recommendations = array_slice($recommendations, 0, $limit);
			}
			return $recommendations;
		}

		//add similar but not popular
		$similar_but_not_popular = array_diff($similar_items, $similar_and_popular);
		$recommendations = array_merge($similar_and_popular, $similar_but_not_popular);
		$item_count += count($similar_but_not_popular);
		file_put_contents('plista.log', "\n" . date('c') . " 2. recommend $itemid $domainid $userid: $item_count similar items found \n", FILE_APPEND);
		if ($item_count >= $limit) {
			if ($item_count > $limit) {
				$recommendations = array_slice($recommendations, 0, $limit);
			}
			return $recommendations;
		}

		//add popular but not similar
		$popular_but_not_similar = array_diff($popular_items, $similar_and_popular);
		$recommendations = array_merge($recommendations, $popular_but_not_similar);
		$item_count += count($popular_but_not_similar);
		if ($item_count >= $limit) {
			if ($item_count > $limit) {
				$recommendations = array_slice($recommendations, 0, $limit);
			}
		}
		file_put_contents('plista.log', "\n" . date('c') . " 3. recommend $itemid $domainid $userid: $item_count popular items found \n", FILE_APPEND);
		return $recommendations;
	}

	/**
	 * @param $itemid
	 * @param $users
	 * @param $domainid
	 */
	protected function findSimilarItems($itemid, $users, $domainid) {
		$t0 = microtime(true);
		$count = 0;
		$processed_items = array();
		$simObj = new ItemSimilarity();
		foreach ($users as $userid) {
			$userHistory = new UserHistory($domainid, $userid);
			$items_seen = $userHistory->get(100);
			$items_seen = array_diff($items_seen, array($itemid));
			if (empty($items_seen)) {
				continue;
			}
			foreach ($items_seen as $seen) {
				//dont calculate again if item has been already procesed
				if (in_array($seen, $processed_items)) {
					continue;
				}
				$processed_items[] = $seen;
				$seen_history = new ItemHistory($seen);
				$seen_users = $seen_history->get(300);
				if (empty($seen_users)) {
					continue;
				}
				$similarity = count(array_intersect($users, $seen_users))
					/ count(array_unique(array_merge($users, $seen_users)));
				if ($similarity == 0) {
					continue;
				}

				$simObj->set($itemid, $seen, round($similarity, 3));
				$count++;
			}
		}
		if ($count > 0) {
			$simObj->refreshTtl($itemid);
		}
		$t = round(microtime(true) - $t0, 3);
		file_put_contents('plista.log', "\n" . date('c') . " $itemid: $count similar items found in $t seconds \n", FILE_APPEND);
	}


	/* This method handles feedback messages from the contest server. As of now it does nothing. It could be used to look at
	 * the object ids in the feedback message and possibly add those to the data list as well.
	 */
	public function handleFeedback(ContestFeedback $feedback) {

//		if (!empty($feedback->source)) {
//			$itemid = $feedback->source->id;
//			// add id to data file
//		}
//
		if (isset($feedback->target) && !empty($feedback->target)) {
			file_put_contents('feedback.log', date('c') . " Message: {$feedback->__toJSON()} \n", FILE_APPEND);
			$itemid = $feedback->target->id;
			$clickskey = 'clicks:' . $feedback->domain->id;
			$redis = RedisHandler::getConnection();
			$redis->zIncrBy($clickskey, 1, $itemid);
			$redis->expire($clickskey, 86400);
			if ($redis->zCard($clickskey) > 500) {
				$redis->zRemRangeByRank($clickskey, 0, -500);
			}

		}
	}

	/* This is the handler method for error messages from the contest server. Implement your error handling code here.
	 */
	public function handleError(ContestError $error) {
		//echo 'oh no, an error: ' . $error->getMessage();
		$msg = $error->getMessage();
		$pattern = "/invalid items returned:(.*)/";
		if (preg_match($pattern, $msg, $matches)) {
			$invalid_items = explode(',', $matches[1]);
			$blacklist_key = 'bl';
			$redis = RedisHandler::getConnection();
			foreach ($invalid_items as $invalid) {
				$redis->sAdd($blacklist_key, $invalid);
			}
			$redis->expire($blacklist_key, 43200);
		}
		throw new ContestException($error);
	}
}
