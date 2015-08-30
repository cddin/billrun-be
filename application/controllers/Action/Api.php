<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Api abstract action class
 *
 * @package  Action
 * @since    0.8
 */
abstract class ApiAction extends Action_Base {

	/**
	 * how much time to store in cache (seconds)
	 * 
	 * @var int
	 */
	protected $cacheLifetime = 14400;
	
	/**
	 * Set an error message to the controller.
	 * @param string $errorMessage - Error message to send to the controller.
	 * @param object $input - The input the triggerd the error.
	 * @return ALWAYS false.
	 */
	function setError($errorMessage, $input = null) {
		Billrun_Factory::log("Sending Error : {$errorMessage}", Zend_Log::NOTICE);
		$output = array(
			'status' => 0,
			'desc' => $errorMessage,
		);
		if (!is_null($input)) {
			$output['input'] = $input;
		}

		// If failed to report to controller.
		if(!$this->getController()->setOutput(array($output))) {
			Billrun_Factory::log("Failed to set message to controller. message: " . $errorMessage, Zend_Log::CRIT);
		}
		
		return false;
	}
	

	/**
	 * method to store and fetch by global cache layer
	 * 
	 * @param type $params params to be used by cache to populate and store
	 * 
	 * @return mixed the cached results
	 */
	protected function cache($params) {
		if (!isset($params['stampParams'])) {
			$params['stampParams'] = $params['fetchParams'];
		}
		$cache = Billrun_Factory::cache();
		if (empty($cache)) {
			return $this->fetchData($params['fetchParams']);
		}
		$actionName = $this->getAction();
		$cachePrefix = $this->getCachePrefix();
		$cacheKey = Billrun_Util::generateArrayStamp(array_values($params['stampParams']));
		$cachedData = $cache->get($cacheKey, $cachePrefix);
		if (!empty($cachedData)) {
			Billrun_Factory::log("Fetch data from cache for " . $actionName . " api call", Zend_Log::INFO);
		} else {
			$cachedData = $this->fetchData($params['fetchParams']);
			$lifetime = Billrun_Factory::config()->getConfigValue('api.cacheLifetime.' . $actionName, $this->getCacheLifeTime());
			$cache->set($cacheKey, $cachedData, $cachePrefix, $lifetime);
		}
		
		return $cachedData;
	}
	
	/**
	 * method to get cache prefix of this action
	 * 
	 * @return string
	 */
	protected function getCachePrefix() {
		return $this->getAction() . '_';
	}
	
	/**
	 * method to get controller action name
	 * 
	 * @return string
	 */
	protected function getAction() {
		return Yaf_Dispatcher::getInstance()->getRequest()->getActionName();
	}
	
	/**
	 * basic fetch data method used by the cache
	 * 
	 * @param array $params parameters to fetch the data
	 * 
	 * @return boolean
	 */
	protected function fetchData($params) {
		return true;
	}

	/**
	 * method to set api call cache lifetime
	 * @param int $val the cache lifetime (seconds)
	 */
	protected function setCacheLifeTime($val) {
		$this->cacheLifetime = $val;
	}
	
	/**
	 * method to get api call cache lifetime
	 * @return int $val the cache lifetime (seconds)
	 */
	protected function getCacheLifeTime() {
		return $this->cacheLifetime;
	}

}
