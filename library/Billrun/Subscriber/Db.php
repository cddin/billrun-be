<?php

/**
 * 
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing subscriber class based on database
 *
 * @package  Billing
 * @since    4.0
 * @todo This class sometimes uses Uppercase keys and sometimes lower case keys. [IMSI and imsi]. 
 * There should be a convertor in the set and get function so that the keys will ALWAYS be lower or upper.
 * This way whoever uses this class can send whatever he wants in the key fields.
 */
class Billrun_Subscriber_Db extends Billrun_Subscriber {

	/**
	 * True if the query handlers are loaded.
	 * @var boolean
	 */
	static $queriesLoaded = false;

	/**
	 * Construct a new subscriber DB instance.
	 * @param array $options - Array of initialization parameters.
	 */
	public function __construct($options = array()) {
		parent::__construct($options);

		// Check that the queries are loaded.
		if (!self::$queriesLoaded) {
			self::$queriesLoaded = true;

			// Register all the query handlers.
			// TODO: Move the list of query types to conf to be created here by reflection.
			Billrun_Subscriber_Query_Manager::register(new Billrun_Subscriber_Query_Types_Imsi());
			Billrun_Subscriber_Query_Manager::register(new Billrun_Subscriber_Query_Types_Msisdn());
			Billrun_Subscriber_Query_Manager::register(new Billrun_Subscriber_Query_Types_Sid());
			Billrun_Subscriber_Query_Manager::register(new Billrun_Subscriber_Query_Types_Custom());
		}
	}

	/**
	 * method to load subsbscriber details
	 * 
	 * @param array $params load by those params 
	 * @return true if successful.
	 */
	public function load($params) {
		$subscriberQuery = Billrun_Subscriber_Query_Manager::handle($params);
		if ($subscriberQuery === false) {
			Billrun_Factory::log('Cannot identify subscriber. Require phone or imsi to load. Current parameters: ' . print_R($params, 1), Zend_Log::ALERT);
			return false;
		}

//		if (!isset($params['time'])) {
//			$datetime = time();
//		} else {
//			$datetime = strtotime($params['time']);
//		}
//		$queryParams['from'] = array('$lt' => new MongoDate(strtotime($datetime)));
//		$queryParams['to'] = array('$gt' => new MongoDate($datetime));


		$data = $this->customerQueryDb($subscriberQuery);
		if (!$data) {
			Billrun_Factory::log('Failed to load subscriber data for params: ' . print_r($params, 1), Zend_Log::NOTICE);
			return false;
		}

		$this->data = $data;
		return true;
	}

	/**
	 * Get the customer from the db.
	 * @param array $params - Input params to get a subscriber by.
	 * @return array Raw data of mongo raw. False if none found.
	 */
	protected function customerQueryDb($params) {
		$coll = Billrun_Factory::db()->subscribersCollection();
		$results = $coll->query($params)->cursor()->limit(1)->current();
		if ($results->isEmpty()) {
			return false;
		}
		return $results->getRawData();
	}

	/**
	 * method to save subsbscriber details
	 */
	public function save() {
		return true;
	}

	/**
	 * method to delete subsbscriber entity
	 */
	public function delete() {
		return true;
	}

	public function isValid() {
		return true;
	}

	public function getSubscribersByParams($params, $availableFields) {
		
	}

	public function getList($startTime, $endTime, $page, $size, $aid = null) {
		$startTimeMongoDate = new MongoDate($startTime);
		$endTimeMongoDate = new MongoDate($endTime);
		if ($aid) {
			$page = 0;
			$size = 1;
		}
		$pipelines[] = array(
			'$match' => array(
				'$or' => array(
					array( // Subscriber records
						'type' => 'subscriber',
						'plan' => array(
							'$exists' => 1
						),
						'$or' => array(
							array(
								'from' => array(// plan started during billing cycle
									'$gte' => $startTimeMongoDate,
									'$lt' => $endTimeMongoDate,
								),
							),
							array(
								'to' => array(// plan ended during billing cycle
									'$gte' => $startTimeMongoDate,
									'$lt' => $endTimeMongoDate,
								),
							),
							array(// plan started before billing cycle and ends after
								'from' => array(
									'$lt' => $startTimeMongoDate
								),
								'to' => array(
									'$gte' => $endTimeMongoDate,
								),
							),
							array(// searches for a next plan. used for prepaid plans
								'from' => array(
									'$lte' => $endTimeMongoDate,
								),
								'to' => array(
									'$gt' => $endTimeMongoDate,
								),
							),
						)
					),
					array( // Account records
						'type' => 'account',
						'from' => array(
							'$lte' => $endTimeMongoDate,
						),
						'to' => array(
							'$gte' => $startTimeMongoDate,
						),
					),
				)
			)
		);
		if ($aid) {
			$pipelines[count($pipelines) - 1]['$match']['aid'] = intval($aid);
		}
		$pipelines[] = array(
			'$sort' => array(
				'aid' => 1,
				'sid' => 1,
				'plan' => 1,
				'from' => 1,
			),
		);
		$pipelines[] = array(
			'$group' => array(
				'_id' => array(
					'aid' => '$aid',
				),
				'sub_plans' => array(
					'$push' => array(
						'type' => '$type',
						'sid' => '$sid',
						'plan' => '$plan',
						'from' => '$from',
						'to' => '$to',
						'plan_activation' => '$plan_activation',
						'plan_deactivation' => '$plan_deactivation',
						'name' => '$name',
						'address' => '$address',
					),
				),
				'card_token' => array(
					'$first' => '$card_token'
				),
			),
		);
		$pipelines[] = array(
			'$skip' => $page * $size,
		);
		$pipelines[] = array(
			'$limit' => intval($size),
		);
		$pipelines[] = array(
			'$unwind' => '$sub_plans',
		);
		$pipelines[] = array(
			'$group' => array(
				'_id' => array(
					'aid' => '$_id.aid',
					'sid' => '$sub_plans.sid',
					'plan' => '$sub_plans.plan',
					'name' => '$sub_plans.name',
					'type' => '$sub_plans.type',
					'address' => '$sub_plans.address',
				),
				'plan_dates' => array(
					'$push' => array(
						'from' => '$sub_plans.from',
						'to' => '$sub_plans.to',
						'plan_activation' => '$sub_plans.plan_activation',
						'plan_deactivation' => '$sub_plans.plan_deactivation',
					),
				),
				'card_token' => array(
					'$first' => '$card_token'
				),
			),
		);
		$pipelines[] = array(
			'$project' => array(
				'_id' => 0,
				'id' => '$_id',
				'plan_dates' => 1,
				'card_token' => 1,
			)
		);
		$coll = Billrun_Factory::db()->subscribersCollection();
		$results = iterator_to_array($coll->aggregate($pipelines));
		return $this->parseActiveSubscribersOutput($results, $startTime, $endTime);
	}

	/**
	 * @param array $outputArr
	 * @param int $time
	 * @return array
	 */
	protected function parseActiveSubscribersOutput($outputArr, $startTime, $endTime) {
		if (isset($outputArr['success']) && $outputArr['success'] === FALSE) {
			return array();
		} else {
			$subscriber_general_settings = Billrun_Config::getInstance()->getConfigValue('subscriber', array());
			if (is_array($outputArr) && !empty($outputArr)) {
				$retData = array();
				$lastSid = null;
				$accountData = array();
				foreach ($outputArr as $subscriberPlan) {
					$type = $subscriberPlan['id']['type'];
					$name = $subscriberPlan['id']['name'];
					if ($type === 'account') {
						$accountData['attributes'] = array(
							'name' => $name,
							'address' => $subscriberPlan['id']['address'],
							'payment_details' => $this->getPaymentDetails($subscriberPlan),
						);
						continue;
					}
					$aid = $subscriberPlan['id']['aid'];
					$sid = $subscriberPlan['id']['sid'];
					$plan = $subscriberPlan['id']['plan'];
					if ($lastSid && ($lastSid != $sid)) {
						$retData[$lastAid]['subscribers'][] = Billrun_Subscriber::getInstance(array_merge(array('data' => $subscriberEntry), $subscriber_general_settings));
						$subscriberEntry = array();
					}
					$subscriberEntry['aid'] = $aid;
					$subscriberEntry['sid'] = $sid;
					$subscriberEntry['name'] = $name;
					$subscriberEntry['next_plan'] = NULL;
					$subscriberEntry['next_plan_activation'] = NULL;
					$subscriberEntry['time'] = $subscriber_general_settings['time'] = $endTime - 1;
					$activeDates = array();
					foreach ($subscriberPlan['plan_dates'] as $dates) {
						if ($dates['to']->sec > $endTime) { // we found the next_plan
							$subscriberEntry['next_plan'] = $plan;
							$subscriberEntry['next_plan_activation'] = date(Billrun_Base::base_dateformat, max($startTime, $dates['plan_activation']->sec));
							if ($dates['from']->sec == $endTime) { // the current date range is completely in the next cycle
								continue;
							}
						}
						$from = date(Billrun_Base::base_dateformat, max($startTime, $dates['from']->sec));
						$to = date(Billrun_Base::base_dateformat, min($endTime - 1, $dates['to']->sec)); // make the 'to' inclusive
						$planActivation = date(Billrun_Base::base_dateformat, $dates['plan_activation']->sec);
						if (!empty($dates['plan_deactivation'])) {
							$planDeactivation = date(Billrun_Base::base_dateformat, $dates['plan_deactivation']->sec);
						} else {
							$planDeactivation = NULL;
						}
						if ($activeDates) {
							$lastTo = &$activeDates[count($activeDates) - 1]['to'];
							if ((($lastTo != $from) && (date(Billrun_Base::base_dateformat, strtotime('+1 day', strtotime($lastTo))) == $from)) || $lastTo == $from) {
								$lastTo = $to;
							} else {
								$activeDateArr = array('from' => $from, 'to' => $to, 'plan_activation' => $planActivation);
								if (!empty($planDeactivation)) {
									$activeDateArr['plan_deactivation'] = $planDeactivation;
								}
								$activeDates[] = $activeDateArr;
							}
						} else {
							$activeDateArr = array('from' => $from, 'to' => $to, 'plan_activation' => $planActivation);
							if (!empty($planDeactivation)) {
								$activeDateArr['plan_deactivation'] = $planDeactivation;
							}
							$activeDates[] = $activeDateArr;
						}
					}
					$subscriberEntry['plans'][] = array('name' => $plan, 'active_dates' => $activeDates);
					$lastAid = $aid;
					$lastSid = $sid;
				}
				$retData[$lastAid]['subscribers'][] = Billrun_Subscriber::getInstance(array_merge(array('data' => $subscriberEntry), $subscriber_general_settings));
				$retData[$lastAid] = array_merge($retData[$lastAid], $accountData);
//				foreach ($outputArr as $account) {
//					if (isset($account['subscribers'])) {
//						foreach ($account['subscribers'] as $subscriber) {
//							if (isset($subscriber['occ']) && is_array($subscriber['occ'])) {
//								$credits = array();
//								foreach ($subscriber['occ'] as $credit) {
//									$credit['aid'] = $concat['data']['aid'];
//									$credit['sid'] = $concat['data']['sid'];
//									$credit['plan'] = $concat['data']['plan'];
//									$credits[] = $credit;
//								}
//								$concat['data']['credits'] = $credits;
//							}
//
//							foreach (self::getExtraFieldsForBillrun() as $field) {
//								if (isset($subscriber[$field])) {
//									$concat['data'][$field] = $subscriber[$field];
//								}
//							}
//							$subscriber_settings = array_merge($subscriber_general_settings, $concat);
//							$retData[intval($aid)][] = Billrun_Subscriber::getInstance($subscriber_settings);
//						}
//					}
//				}
				ksort($retData); // maybe this will help the aid index to stay in memory
				return $retData;
			} else {
				return array();
			}
		}
	}

	public function getListFromFile($file_path, $time) {
		
	}

	public function getCredits($billrun_key, $retEntity = false) {
		return array();
	}

	public function getServices($billrun_key, $retEntity = false) {
		return array();
	}
	
	protected function getPaymentDetails($details) {
		if (!empty($token = $details['card_token'])) {
			return Billrun_Util::getTokenToDisplay($token);
		}
		return '';
	}

}
