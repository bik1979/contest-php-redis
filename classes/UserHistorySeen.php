<?php

/**
 * Created by JetBrains PhpStorm.
 * User: vr
 * Date: 19/04/13
 * Time: 11:14
 * To change this template use File | Settings | File Templates.
 */
class UserHistorySeen extends UserHistory {


	/**
	 * a different list  per domain
	 * @param string $domainid
	 * @param string $userid
	 */
	public function __construct($domainid, $userid) {
		$this->memkey = sprintf(static::KEY, "seen:$userid", $domainid);
	}

}
