<?php
/* This is the reference implementation of a ContestHandler, which does nothing more than store the last items it sees
 * (through impressions), and return them in reverse order.
 */

class ContestHandlerRedis implements ContestHandler {
	// holds the instance, singleton pattern
	private static $instance;

	private function __construct() {
	}

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ContestHandlerRedis();
		}

		return self::$instance;
	}

	/* This method handles received impressions. First it loads the data file, then checks whether the current item is
	 * present in the data. If not, it prepends the new item id and writes the data file back. It then checks whether
	 * it needs to generate a recommendation and if so takes object ids from the front of the data (excluding the new one)
	 * and sends those back to the contest server.
	 */
	public function handleImpression(ContestImpression $impression) {
		$itemid = isset($impression->item->id) ? $impression->item->id : 0;
		$recommendable = isset($impression->item->recommendable) ? $impression->item->recommendable : true;
		$userid = isset($impression->client->id) ? $impression->client->id : 0;
		$domainid = $impression->domain->id;

		$itemPublisherList = new ItemSortedList($domainid);

		$userHistoryList = null;
		if (!empty($userid)) {
			$userHistoryList = new UserHistory($domainid, $userid);
		}

		// check whether a recommendation is expected. if the flag is set to false, the current message is just a training message.
		if ($impression->recommend) {
			$candidates_list = $itemPublisherList->get(10 * $impression->limit);
			//don't return current item
			$has_current_item = array_search($itemid, $candidates_list);
			if ($has_current_item !== false) {
				unset($candidates_list[$has_current_item]);
			}
			$items_seen = array();
			if ($userHistoryList != null) {
				$items_seen = $userHistoryList->get(15);
			}
			$result_data = array();
			$skipped = array();
			$item_count = 0;
			foreach ($candidates_list as $id) {
				$item_in_history = in_array($id, $items_seen);
				//skip items already seen recently by the user
				if ($item_in_history !== false) {
					$skipped[] = $id;
					continue;
				}
				$data_object = new stdClass;
				$data_object->id = $id;
				$result_data[] = $data_object;
				$item_count++;
				if ($item_count == $impression->limit) {
					break;
				}
			}

			if (count($result_data) < $impression->limit) {
				if ((count($skipped) + count($result_data)) < $impression->limit) {
					throw new ContestException('not enough data', 500);
				}
				//add skipped items as last option
				foreach ($skipped as $id) {
					$data_object = new stdClass;
					$data_object->id = $id;
					$result_data[] = $data_object;
					$item_count++;
					if ($item_count == $impression->limit) {
						break;
					}
				}
			}

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
			if ($userHistoryList != null) {
				$size = $userHistoryList->push($itemid);
				if ($size > 10) {
					file_put_contents('plista.log', "\n" . date('c') . " user $userid - $domainid:  history size:$size \n", FILE_APPEND);
				}
				//if we have userid, push it to the item history
				$itemHistory = new ItemHistory($itemid);
				$size = $itemHistory->push($userid);
				//if there's enough new data, try to find similar items
				if (($size % 50) == 0) {
					file_put_contents('plista.log', "\n" . date('c') . " item $itemid: history size:$size \n", FILE_APPEND);
					$users_list = $itemHistory->get(200);
					$this->findSimilarItems($itemid, $users_list, $domainid);
				}
			}
		}
	}

	/**
	 * @param $itemid
	 * @param $users
	 * @param $domainid
	 */
	protected function findSimilarItems($itemid, $users, $domainid) {
		$t0 = time();
		$count = 0;
		foreach ($users as $userid) {
			$userHistory = new UserHistory($domainid, $userid);
			$items_seen = $userHistory->get(100);
			if (empty($items_seen)) {
				continue;
			}
			foreach ($items_seen as $seen) {
				$seen_history = new ItemHistory($seen);
				$seen_users = $seen_history->get(200);
				if (empty($seen_users)) {
					continue;
				}
				$similarity = count(array_intersect($users, $seen_users))
					/ count(array_unique(array_merge($users, $seen_users)));
				$simObj = new ItemSimilarity();
				$simObj->set($itemid, $seen, round($similarity, 3));
				$count++;
			}
		}
		$t = time() - $t0;
		file_put_contents('plista.log', "\n" . date('c') . " $count similar items found in $t seconds \n", FILE_APPEND);
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
//		if (!empty($feedback->target)) {
//			$itemid = $feedback->target->id;
//			// add id to data file
//		}
	}

	/* This is the handler method for error messages from the contest server. Implement your error handling code here.
	 */
	public function handleError(ContestError $error) {
		//echo 'oh no, an error: ' . $error->getMessage();
		throw new ContestException($error);
	}
}