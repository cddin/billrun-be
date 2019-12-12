<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class Billrun_Account_External extends Billrun_Account {
	
	protected static $type = 'external';
	
	protected static $queryBaseKeys = ['id', 'time', 'limit'];
	
	protected $remote;

	public function __consrtuct($options = []) {
		parent::__construct($options);
		$this->remote = Billrun_Factory::config()->getConfigValue('subscribers.external_url', '');
		$this->remote_billable_url = Billrun_Factory::config()->getConfigValue('subscribers.billable.url', '');
	}
	

	public function getBillable(\Billrun_DataTypes_MongoCycleTime $cycle, $page = 0 , $size = 100, $aids = []) {
			// Prepare request
			$requestParams = [
				'start_date' => date('Y-m-d',$cycle->start()->sec),
				'end_date' => date('Y-m-d',$cycle->end()->sec),
				'page' => $page,
				'size' => $size
			];

			if(!empty($aids)) {
				$requestParams['aids'] = $aids;
			}
			//Actually  do the request
			$results = Billrun_Util::sendRequest($this->remote_billable_url,$requestParams);
			$results = [[
				"aid"=>132,
				"from" => "2019-01-01 09:00:00",
				"to" => "2019-10-01 09:00:00",
				"country" => "united_states",
				"email" => "a@gmail.com",
				"zip_code" => "4229652",
				"invoice_shipping_method" => "post"
				,"invoice_detailed"=>false
				,"address" => "xxxxxxxx",
				"custom_field1" => "123",
				"tenant_return_url" => "http://abs.com",
				"payment_gateway"=>[
					"active"=>[
						"name" => "xxx",
						"card_token" => "1234",
						"card_expiration" => "2019-01-01 09:00:00",
						"personal_id" => "xxxxxx",
						"transaction_exhausted"=>false,
						"generate_token_time" => "2019-01-01 09:00:00",
						"auth_number" => "xxxxx",
						"four_digits" => "1234"

					]],
				"creation_time" => "2019-09-01 10:00:00",
				"subscription_revisions"=>[]
			]];
			//Check for errors
			if(!empty($results)) {
				Billrun_Factory::log('Failed to retrive valid results  for billable, remote returned no data.',Zend::WARN);
				return [];
			}
			if(!empty($results['status'])) {
				Billrun_Factory::log("Remote server return an error (status : {$results['status']}) on request : ".json_encode($requestParams),Zend::WARN);
				return [];
			}
			// Preform translation if needed and return results
			return $results['data'];
	}


	/**
	 * Overrides parent abstract method
	 */
	protected function getAccountsDetails($query) {
		$res = Billrun_Util::sendRequest($this->remote, json_encode($query));
		$accounts = [];
		if (!$res) {
			Billrun_Factory::log()->log(get_class() . ': could not complete request to' . $this->remote, Zend_Log::NOTICE);
			return false;
		}
		foreach ($res as $account) {
			$accounts[] = new Mongodloid_Entity($account);
		}
		return $accounts;
	}
	
	/**
	 * Overrides parent abstract method
	 */
	protected function getAccountDetails($queries) {
		$externalQuery = [];
		foreach ($queries as &$query) {
			$query = $this->buildParams($query);
			$externalQuery[] = $query;
		}
		$results = json_decode(Billrun_Util::sendRequest($this->remote, json_encode($externalQuery)), true);
		if (!$results) {
			Billrun_Factory::log()->log(get_class() . ': could not complete request to' . $this->remote, Zend_Log::NOTICE);
			return false;
		}
		return array_reduce($results, function($acc, $currentSub) {
			$acc[] = new Mongodloid_Entity($currentSub);
			return $acc;
		}, []);
	}


	
	/** 
	 * Method to Save as 'Close And New' item
	 */
	public function closeAndNew($set_values, $remove_values = array()) {
		
	}

	protected function buildParams(&$query) {

		if (isset($query['EXTRAS'])) {
			unset($query['EXTRAS']);
		}
		$params = [];
		foreach ($query as $key => $value) {
			if (!in_array($key, static::$queryBaseKeys)) {
				if (is_array($value)) {
					foreach ($value as $currKey => $currVal) {
						$params[] = [
						'key' => $key,
						'operator' => $currKey,
						'value' => $currVal
						];
					}
				} else {
					$params[] = [
						'key' => $key,
						'operator' => 'equal',
						'value' => $value
						];
				}
				unset($query[$key]);
			}
		}
		$query['params'] = $params;
		return $query;
	}

}

