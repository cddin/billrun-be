<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator for  pricing  billing lines with customer price.
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_CustomerPricing extends Billrun_Calculator {

	const DEF_CALC_DB_FIELD = 'aprice';

	public $pricingField = self::DEF_CALC_DB_FIELD;
	static protected $type = "pricing";

	/**
	 *
	 * @var boolean is customer price vatable by default
	 */
	protected $vatable = true;

	/**
	 * Save unlimited usages to balances
	 * @var boolean
	 */
	protected $unlimited_to_balances = true;
	protected $plans = array();

	/**
	 *
	 * @var Mongodloid_Collection 
	 */
	protected $balances = null;

	/**
	 *
	 * @var int timestamp
	 */
	protected $billrun_lower_bound_timestamp;

	/**
	 * Minimum possible billrun key for newly calculated lines
	 * @var string 
	 */
	protected $active_billrun;

	/**
	 * End time of the active billrun (unix timestamp)
	 * @var int
	 */
	protected $active_billrun_end_time;

	/**
	 * Second minimum possible billrun key for newly calculated lines
	 * @var string
	 */
	protected $next_active_billrun;

	/**
	 * inspect loops in updateSubscriberBalance
	 * @see mongodb update where value equale old value
	 * 
	 * @var int
	 */
	protected $countConcurrentRetries;

	/**
	 * max retries on concurrent balance updates loops
	 * 
	 * @var int
	 */
	protected $concurrentMaxRetries;

	/**
	 * Array of subscriber ids queued for rebalance in rebalance_queue collection
	 * @var array
	 */
	protected $sidsQueuedForRebalance;
	
	/**
	 * balance that customer pricing update
	 * 
	 * @param Billrun_Balance $balance
	 */
	protected $balance;

	public function __construct($options = array()) {
		if (isset($options['autoload'])) {
			$autoload = $options['autoload'];
		} else {
			$autoload = true;
		}

		$options['autoload'] = false;
		parent::__construct($options);

		if (isset($options['calculator']['limit'])) {
			$this->limit = $options['calculator']['limit'];
		}
		if (isset($options['calculator']['vatable'])) {
			$this->vatable = $options['calculator']['vatable'];
		}
		if (isset($options['calculator']['months_limit'])) {
			$this->months_limit = $options['calculator']['months_limit'];
		}
		if (isset($options['calculator']['unlimited_to_balances'])) {
			$this->unlimited_to_balances = (boolean) ($options['calculator']['unlimited_to_balances']);
		}
		$this->billrun_lower_bound_timestamp = is_null($this->months_limit) ? 0 : strtotime($this->months_limit . " months ago");
		// set months limit
		if ($autoload) {
			$this->load();
		}
		$this->loadRates();
		$this->loadPlans();
		$this->balances = Billrun_Factory::db()->balancesCollection()->setReadPreference('RP_PRIMARY');
		$this->active_billrun = Billrun_Billrun::getActiveBillrun();
		$this->active_billrun_end_time = Billrun_Util::getEndTime($this->active_billrun);
		$this->next_active_billrun = Billrun_Util::getFollowingBillrunKey($this->active_billrun);
		// max recursive retrues for value=oldValue tactic
		$this->concurrentMaxRetries = (int) Billrun_Factory::config()->getConfigValue('updateValueEqualOldValueMaxRetries', 8);
		$this->sidsQueuedForRebalance = array_flip(Billrun_Factory::db()->rebalance_queueCollection()->distinct('sid'));
	}

	protected function getLines() {
		$query = array();
		$query['type'] = array('$in' => array('ggsn', 'smpp', 'mmsc', 'smsc', 'nsn', 'tap3', 'credit','nrtrde'));
		return $this->getQueuedLines($query);
	}

	/**
	 * execute the calculation process
	 * @TODO this function mighh  be a duplicate of  @see Billrun_Calculator::calc() do we really  need the diffrence  between Rate/Pricing? (they differ in the plugins triggered)
	 */
	public function calc() {
		Billrun_Factory::dispatcher()->trigger('beforePricingData', array('data' => $this->data));
		$lines_coll = Billrun_Factory::db()->linesCollection();

		$lines = $this->pullLines($this->lines);
		foreach ($lines as $key => $line) {
			if ($line) {
				Billrun_Factory::dispatcher()->trigger('beforePricingDataRow', array('data' => &$line));
				//Billrun_Factory::log("Calculating row: ".print_r($item,1),  Zend_Log::DEBUG);
				$line->collection($lines_coll);
				if ($this->isLineLegitimate($line)) {
					if ($this->updateRow($line) === FALSE) {
						unset($this->lines[$line['stamp']]);
						continue;
					}
					$this->data[$line['stamp']] = $line;
				}
				//$this->updateLinePrice($item); //@TODO  this here to prevent divergance  between the priced lines and the subscriber's balance/billrun if the process fails in the middle.
				Billrun_Factory::dispatcher()->trigger('afterPricingDataRow', array('data' => &$line));
			}
		}
		Billrun_Factory::dispatcher()->trigger('afterPricingData', array('data' => $this->data));
	}

	public function updateRow($row) {
		if (isset($this->sidsQueuedForRebalance[$row['sid']])) {
			return false;
		}
		try {
			$this->countConcurrentRetries = 0;
			Billrun_Factory::dispatcher()->trigger('beforeCalculatorUpdateRow', array($row, $this));
			$rate = $this->getRowRate($row);

			//TODO  change this to be configurable.
			$pricingData = array();

			$usage_type = $row['usaget'];

			if (isset($row['usagev']) || $row['charging_type'] == 'prepaid') {  // for prepaid, volume is by balance left over
				$volume = $row['usagev'];
				$plan_name = isset($row['plan']) ? $row['plan'] : null;
				if ($row['type'] == 'credit') {
					$accessPrice = $this->getAccessPrice($rate, $usage_type, $plan_name);
					$pricingData = array($this->pricingField => $accessPrice + self::getPriceByRate($rate, $usage_type, $volume, $row['plan']));
				} else if ($row['type'] == 'service') {
					$pricingData = array($this->pricingField => self::getPriceByRate($rate, $usage_type, $volume, $plan_name));
				} else {
					$pricingData = $this->updateSubscriberBalance($row, $usage_type, $rate);
					if ($pricingData === FALSE) {
						return false;
					}
				}

				if ($this->isBillable($rate)) {
					if (!$pricingData) {
						return false;
					}

					// billrun cannot override on api calls
					if (!isset($row['billrun']) || $row['source'] != 'api') {
						$pricingData['billrun'] = $row['urt']->sec <= $this->active_billrun_end_time ? $this->active_billrun : $this->next_active_billrun;
					}
				}
			} else {
				Billrun_Factory::log("Line with stamp " . $row['stamp'] . " is missing volume information", Zend_Log::ALERT);
				return false;
			}

			$pricingDataTxt = "Saving pricing data to line with stamp: " . $row['stamp'] . ".";
			foreach ($pricingData as $key => $value) {
				$pricingDataTxt .= " " . $key . ": " . $value . ".";
			}
			Billrun_Factory::log($pricingDataTxt, Zend_Log::DEBUG);
			$row->setRawData(array_merge($row->getRawData(), $pricingData));

			Billrun_Factory::dispatcher()->trigger('afterCalculatorUpdateRow', array(&$row, $this));
			return $row;
		} catch (Exception $e) {
			Billrun_Factory::log('Line with stamp ' . $row['stamp'] . ' crashed when trying to price it. got exception :' . $e->getCode() . ' : ' . $e->getMessage() . "\n trace :" . $e->getTraceAsString(), Zend_Log::ERR);
			return false;
		}
	}

	/**
	 * Gets the subscriber's balance. If it does not exist, creates it.
	 * 
	 * @param type $row
	 * 
	 * @return Billrun_Balance
	 * 
	 * @todo Add compatiblity to prepaid
	 */
	public function loadSubscriberBalance($row) {
		$plan = Billrun_Factory::plan(array('name' => $row['plan'], 'time' => $row['urt']->sec, 'disableCache' => true));
		$plan_ref = $plan->createRef();
		if (is_null($plan_ref)) {
			Billrun_Factory::log('No plan found for subscriber ' . $row['sid'], Zend_Log::ALERT);
			$row['usagev'] = 0;
			return false;
		}

		$balance = new Billrun_Balance($row);
		if (!$balance || !$balance->isValid()) {
			Billrun_Factory::log("couldn't get balance for subscriber: " . $row['sid'], Zend_Log::INFO);
			$row['usagev'] = 0;
			return false;
		} else {
			Billrun_Factory::log("Found balance  for subscriber " . $row['sid'], Zend_Log::DEBUG);
		}
		$this->balance = $balance;
		return true;
	}

	/**
	 * get subscriber plan object
	 * identification using the balance collection
	 * 
	 * @param array $sub_balance the subscriber balance
	 * @return type
	 */
	protected function getPlan($sub_balance) {
		$subscriber_current_plan = $this->getBalancePlan($sub_balance);
		return Billrun_Factory::plan(array('data' => $subscriber_current_plan));
	}

	/**
	 * Get pricing data for a given rate / subcriber.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @param string $usageType The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param mixed $sub_balance the  subscriber that generated the usage.
	 * @param Billrun_Plan $plan the subscriber's current plan
	 * @return Array the 
	 * @todo refactoring the if-else-if-else-if-else to methods
	 */
	protected function getLinePricingData($volume, $usageType, $rate, $sub_balance, $plan) {
		$accessPrice = $this->getAccessPrice($rate, $usageType, $plan->getName());
		$ret = array();
		if ($plan->isRateInBasePlan($rate, $usageType)) {
			$planVolumeLeft = $plan->usageLeftInBasePlan($sub_balance, $rate, $usageType);
			$volumeToCharge = $volume - $planVolumeLeft;
			if ($volumeToCharge < 0) {
				$volumeToCharge = 0;
				$ret['in_plan'] = $volume;
				$accessPrice = 0;
			} else if ($volumeToCharge > 0) {
				if ($planVolumeLeft > 0) {
					$ret['in_plan'] = $volume - $volumeToCharge;
				}
				$ret['over_plan'] = $volumeToCharge;
			}
		} else if ($plan->isRateInPlanGroup($rate, $usageType)) {
			$groupVolumeLeft = $plan->usageLeftInPlanGroup($sub_balance, $rate, $usageType);
			$volumeToCharge = $volume - $groupVolumeLeft;
			if ($volumeToCharge < 0) {
				$volumeToCharge = 0;
				$ret['in_group'] = $ret['in_plan'] = $volume;
				$accessPrice = 0;
			} else if ($volumeToCharge > 0) {
				if ($groupVolumeLeft > 0) {
					$ret['in_group'] = $ret['in_plan'] = $volume - $volumeToCharge;
				}
				if ($plan->getPlanGroup() !== FALSE) { // verify that after all calculations we are in group
					$ret['over_group'] = $ret['over_plan'] = $volumeToCharge;
				} else {
					$ret['out_group'] = $ret['out_plan'] = $volumeToCharge;
				}
			}
			if ($plan->getPlanGroup() !== FALSE) {
				$ret['arategroup'] = $plan->getPlanGroup();
			}
		} else { // else if (dispatcher->chain_of_responsibilty)->isRateInPlugin {dispatcher->trigger->calc}
			$ret['out_plan'] = $volumeToCharge = $volume;
		}

		$price = $accessPrice + self::getPriceByRate($rate, $usageType, $volumeToCharge, $plan);
		//Billrun_Factory::log("Rate : ".print_r($typedRates,1),  Zend_Log::DEBUG);
		$ret[$this->pricingField] = $price;
		return $ret;
	}

	/**
	 * Determines if a rate should not produce billable lines, but only counts the usage
	 * @param Mongodloid_Entity|array $rate the input rate
	 * @return boolean
	 */
	public function isBillable($rate) {
		return !isset($rate['billable']) || $rate['billable'] === TRUE;
	}

	/**
	 * Override parent calculator to save changes with update (not save)
	 */
	public function writeLine($line, $dataKey) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteLine', array('data' => $line, 'calculator' => $this));
		$save = array();
		$saveProperties = array($this->pricingField, 'billrun', 'over_plan', 'in_plan', 'out_plan', 'plan_ref', 'usagesb', 'arategroup', 'over_arate', 'over_group', 'in_group', 'in_arate');
		foreach ($saveProperties as $p) {
			if (!is_null($val = $line->get($p, true))) {
				$save['$set'][$p] = $val;
			}
		}
		$where = array('stamp' => $line['stamp']);
		if ($save) {
			Billrun_Factory::db()->linesCollection()->update($where, $save);
		}
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteLine', array('data' => $line, 'calculator' => $this));
		if (!isset($line['usagev']) || $line['usagev'] === 0) {
			$this->removeLineFromQueue($line);
			unset($this->data[$dataKey]);
		}
	}

	/**
	 * Calculates the price for the given volume (w/o access price)
	 * 
	 * @param array $rate the rate entry
	 * @param string $usage_type the usage type
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @param object $plan The plan the line is associate to
	 * 
	 * @return int the calculated price
	 */
	public static function getPriceByRate($rate, $usage_type, $volume, $plan = null) {
		$rates_arr = self::getRatesArray($rate, $usage_type, $plan);
		$price = 0;
		foreach ($rates_arr as $currRate) {
			if (0 == $volume) { // volume could be negative if it's a refund amount
				break;
			}//break if no volume left to price.
			$volumeToPriceCurrentRating = ($volume - $currRate['to'] < 0) ? $volume : $currRate['to']; // get the volume that needed to be priced for the current rating
			if (isset($currRate['ceil'])) {
				$ceil = $currRate['ceil'];
			} else {
				$ceil = true;
			}
			if ($ceil) {
				$price += floatval(ceil($volumeToPriceCurrentRating / $currRate['interval']) * $currRate['price']); // actually price the usage volume by the current 	
			} else {
				$price += floatval($volumeToPriceCurrentRating / $currRate['interval'] * $currRate['price']); // actually price the usage volume by the current 
			}
			$volume = $volume - $volumeToPriceCurrentRating; //decrease the volume that was priced
		}
		return $price;
	}
	
	/**
	 * Calculates the volume for the given price (w/o access price)
	 * 
	 * @param array $rate the rate entry
	 * @param string $usage_type the usage type
	 * @param int $price The price
	 * @param object $plan The plan the line is associate to
	 * 
	 * @return int the calculated volume
	 */
	public static function getVolumeByRate($rate, $usage_type, $price, $plan = null) {
		$rates_arr = self::getRatesArray($rate, $usage_type, $plan);
		$volume = 0;
		$lastRateFrom = 0;
		foreach ($rates_arr as $currRate) {
			if ($price == 0) {
				break;
			}
			
			$volumeAvailableInCurrentRate = floor(($price / $currRate['price']) / $currRate['interval']) * $currRate['interval']; // In case of no limit
			if (isset($currRate['from'])) {
				$lastRateFrom = $currRate['from'];
			}
			$currentRateMaxVolume = $currRate['to'] - $lastRateFrom;
			$lastRateFrom = $currRate['to']; // Support the case of rate without "from" field
			$volumeInCurrentRate = ($volumeAvailableInCurrentRate < $currentRateMaxVolume ? $volumeAvailableInCurrentRate : $currentRateMaxVolume); // Checks limit for current rate
			if ($volumeInCurrentRate == 0) {
				break;
			}
			$price -= ($volumeInCurrentRate * $currRate['price']);
			$volume += $volumeInCurrentRate;
		}
		return $volume;
	}
	
	protected static function getRatesArray($rate, $usage_type, $plan = null) {
		if (!is_null($plan) && !empty($plan_name = $plan->getName()) && isset($rate['rates'][$usage_type]['rate'][$plan_name])) {
			return $rate['rates'][$usage_type]['rate'][$plan_name];
		}
		if (isset($rate['rates'][$usage_type]['rate']['BASE'])) {
			return $rate['rates'][$usage_type]['rate']['BASE'];
		}
		return $rate['rates'][$usage_type]['rate'];
	}
	
	/**
	 * Gets correct access price from rate by priority order: 
	 *	1. Access for the customer plan.
	 *	2. Base plan
	 *	3. Numeric value
	 *	4. Return 0
	 * 
	 * @param type $rate pricing rate row
	 * @param type $usageType
	 * @param type $planName
	 * @return int Access price
	 */
	protected function getAccessPrice($rate, $usageType, $planName) {
		if (isset($rate['rates'][$usageType]['access'])) {
			$access = $rate['rates'][$usageType]['access'];
			if (isset($access[$planName])) {
				return $access[$planName];
			}
			if (isset($access['BASE'])) {
				return $access['BASE'];
			}
			if (is_numeric($access)) {
				return $access;
			}
		}
		
		return 0;
	}

	/**
	 * Update the subscriber balance for a given usage
	 * Method is recursive - it tries to update subscriber balances with value=oldValue tactic
	 * There is max retries for the recursive to run and the value is configured
	 * 
	 * @param Mongodloid_Entity $row the input line
	 * @param string $usage_type The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * 
	 * @return mixed array with the pricing data on success, false otherwise
	 * @todo refactoring and make it more abstract
	 * @todo create unit test for this method because it's critical method
	 * @todo add compatiblity to prepaid
	 * 
	 */
	public function updateSubscriberBalance($row, $usage_type, $rate) {
		$row['granted_return_code'] = Billrun_Factory::config()->getConfigValue('prepaid.ok');
		if (!$this->loadSubscriberBalance($row)) { // will load $this->balance
			if ($row['charging_type'] === 'prepaid') {
				$row['granted_return_code'] = Billrun_Factory::config()->getConfigValue('prepaid.customer.no_available_balances');
			}
			Billrun_Factory::dispatcher()->trigger('afterSubscriberBalanceNotFound', array($row->getRawData()));
			return false;
		}
		$balanceRaw = $this->balance->getRawData();
		if ($row['charging_type'] === 'prepaid' && !(isset($row['prepaid_rebalance']) && $row['prepaid_rebalance'])) { // If it's a prepaid row, but not rebalance
			$row['usagev'] = $volume = $this->getPrepaidGrantedVolume($row, $rate, $this->balance, $usage_type);
		} else {
			$volume = $row['usagev'];
		}
		$this->countConcurrentRetries++;
		Billrun_Factory::dispatcher()->trigger('beforeUpdateSubscriberBalance', array($this->balance, &$row, $rate, $this));
		$plan = Billrun_Factory::plan(array('name' => $row['plan'], 'time' => $row['urt']->sec, 'disableCache' => true));
		$balance_totals_key = ($row['charging_type'] === 'postpaid' ? $plan->getBalanceTotalsKey($usage_type, $rate) : $usage_type);
		$tx = $this->balance->get('tx');
		if (!empty($tx) && array_key_exists($row['stamp'], $tx)) { // we're after a crash
			$pricingData = $tx[$row['stamp']]; // restore the pricingData before the crash
			return $pricingData;
		}
		$pricingData = $this->getLinePricingData($volume, $usage_type, $rate, $this->balance, $plan);
		if (isset($row['billrun_pretend']) && $row['billrun_pretend']) {
			return $pricingData;
		}

		$update = array();
		$update['$set']['tx.' . $row['stamp']] = $pricingData;
		if (!isset($this->balance->get('balance')['totals'][$balance_totals_key]['usagev'])) {
			$old_usage = 0;
		} else {
			$old_usage = $this->balance->get('balance')['totals'][$balance_totals_key]['usagev'];
		}
		$balance_id = $this->balance->getId();
		$balance_key = 'balance.totals.' . $balance_totals_key . '.usagev';
		$query = array(
			'_id' => $this->balance->getId()->getMongoID(),
			'$or' => array(
				array($balance_key => $old_usage),
				array($balance_key => array('$exists' => 0))
			)
		);		
		
		if ($row['charging_type'] === 'postpaid') {
			$update['$set']['balance.totals.' . $balance_totals_key . '.usagev'] = $old_usage + $volume;
			$update['$inc']['balance.totals.' . $balance_totals_key . '.cost'] = $pricingData[$this->pricingField];
			$update['$inc']['balance.totals.' . $balance_totals_key . '.count'] = 1;
		} else {
			if (!is_null($this->balance->get('balance.totals.' . $balance_totals_key . '.usagev'))) {
				$update['$set']['balance.totals.' . $balance_totals_key . '.usagev'] = $old_usage + $volume;
			} else {
				$update['$inc']['balance.totals.' . $balance_totals_key . '.cost'] = $pricingData[$this->pricingField];
			}
		}
		// update balance group (if exists)
		if ($plan->isRateInPlanGroup($rate, $usage_type)) {
			$group = $plan->getPlanGroup();
			if ($group !== FALSE) {
				if ($row['charging_type'] === 'postpaid') {
					// @TODO: check if $usage_type should be $key
					$update['$inc']['balance.groups.' . $group . '.' . $usage_type . '.usagev'] = $volume;
					$update['$inc']['balance.groups.' . $group . '.' . $usage_type . '.cost'] = $pricingData[$this->pricingField];
					$update['$inc']['balance.groups.' . $group . '.' . $usage_type . '.count'] = 1;
				} else {
					if (!is_null($this->balance->get('balance.totals.' . $balance_totals_key . '.usagev'))) {
						$update['$inc']['balance.groups.' . $group . '.' . $usage_type . '.usagev'] = $volume;
					} else {
						$update['$inc']['balance.groups.' . $group . '.' . $usage_type . '.cost'] = $pricingData[$this->pricingField];
					}
				}
				if (isset($this->balance->get('balance')['groups'][$group][$usage_type]['usagev'])) {
					$pricingData['usagesb'] = floatval($this->balance->get('balance')['balance']['groups'][$group][$usage_type]['usagev']);
				} else {
					$pricingData['usagesb'] = 0;
				}
			}
		} else {
			$pricingData['usagesb'] = floatval($old_usage);
		}
		$update['$set']['balance.cost'] = $balanceRaw['balance']['cost'] + $pricingData[$this->pricingField];
		$options = array('w' => 1);
		Billrun_Factory::log("Updating balance " . $balance_id . " of subscriber " . $row['sid'], Zend_Log::DEBUG);
		Billrun_Factory::dispatcher()->trigger('beforeCommitSubscriberBalance', array(&$row, &$pricingData, &$query, &$update, $rate, $this));
		$ret = $this->balances->update($query, $update, $options);
		if (!($ret['ok'] && $ret['updatedExisting'])) {
			// failed because of different totals (could be that another server with another line raised the totals). 
			// Need to calculate pricingData from the beginning
			if ($this->countConcurrentRetries >= $this->concurrentMaxRetries) {
				Billrun_Factory::log()->log('Too many pricing retries for line ' . $row['stamp'] . '. Update status: ' . print_r($ret, true), Zend_Log::ALERT);
				return false;
			}
			Billrun_Factory::log('Concurrent write of sid : ' . $row['sid'] . ' line stamp : ' . $row['stamp'] . ' to balance. Update status: ' . print_r($ret, true) . 'Retrying...', Zend_Log::INFO);
			usleep($this->countConcurrentRetries);
			return $this->updateSubscriberBalance($row, $usage_type, $rate);
		}
		Billrun_Factory::log("Line with stamp " . $row['stamp'] . " was written to balance " . $balance_id . " for subscriber " . $row['sid'], Zend_Log::DEBUG);
		$row['tx_saved'] = true; // indication for transaction existence in balances. Won't & shouldn't be saved to the db.
		Billrun_Factory::dispatcher()->trigger('afterUpdateSubscriberBalance', array(array_merge($row->getRawData(), $pricingData), $this->balance, &$pricingData, $this));
		return $pricingData;
	}

	public function getPricingField() {
		return $this->pricingField;
	}

	/**
	 * method to get usage type by balances total key
	 * @param array $counters
	 * @return string
	 * @deprecated since version 2.7
	 */
	protected function getUsageKey($counters) {
		return key($counters); // array pointer will always point to the first key
	}

	/**
	 * removes the transactions from the subscriber's balance to save space.
	 * @param type $row
	 */
	public function removeBalanceTx($row) {
		$query = array(
			'_id' => $this->balance->getId()->getMongoID(),
		);
		$values = array(
			'$unset' => array(
				'tx.' . $row['stamp'] => 1
			)
		);
		$this->balances->update($query, $values);
	}

	/**
	 * @see Billrun_Calculator::getCalculatorQueueType
	 */
	public function getCalculatorQueueType() {
		return self::$type;
	}

	/**
	 * @see Billrun_Calculator::isLineLegitimate
	 */
	public function isLineLegitimate($line) {
		$arate = $this->getRateByRef($line->get('arate', true));
		return !is_null($arate) && (empty($arate['skip_calc']) || !in_array(self::$type, $arate['skip_calc'])) &&
			isset($line['sid']) && $line['sid'] !== false &&
			$line['urt']->sec >= $this->billrun_lower_bound_timestamp;
	}

	/**
	 * 
	 */
	protected function setCalculatorTag($query = array(), $update = array()) {
		parent::setCalculatorTag($query, $update);
		foreach ($this->data as $item) {
			if ($this->isLineLegitimate($item) && !empty($item['tx_saved'])) {
				$this->removeBalanceTx($item); // we can safely remove the transactions after the lines have left the current queue
			}
		}
	}

	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor();
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
	}

	protected function loadPlans() {
		$plans_coll = Billrun_Factory::db()->plansCollection();
		$plans = $plans_coll->query()->cursor();
		foreach ($plans as $plan) {
			$plan->collection($plans_coll);
			$this->plans[strval($plan->getId())] = $plan;
		}
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	public function getRowRate($row) {
		return $this->getRateByRef($row->get('arate', true));
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getBalancePlan($sub_balance) {
		return $this->getPlanByRef($sub_balance->get('current_plan', true));
	}

	protected function getPlanByRef($plan_ref) {
		if (isset($plan_ref['$id'])) {
			$id_str = strval($plan_ref['$id']);
			if (isset($this->plans[$id_str])) {
				return $this->plans[$id_str];
			}
		}
		return null;
	}

	protected function getRateByRef($rate_ref) {
		if (isset($rate_ref['$id'])) {
			$id_str = strval($rate_ref['$id']);
			if (isset($this->rates[$id_str])) {
				return $this->rates[$id_str];
			}
		}
		return null;
	}

	/**
	 * Add plan reference to line
	 * @param Mongodloid_Entity $row
	 * @param string $plan
	 */
	protected function addPlanRef($row, $plan) {
		$planObj = Billrun_Factory::plan(array('name' => $plan, 'time' => $row['urt']->sec, 'disableCache' => true));
		if (!$planObj->get('_id')) {
			Billrun_Factory::log("Couldn't get plan for CDR line : {$row['stamp']} with plan $plan", Zend_Log::ALERT);
			return;
		}
		$row['plan_ref'] = $planObj->createRef();
		return $row->get('plan_ref', true);
	}

	/**
	 * Create a subscriber entry if none exists. Uses an update query only if the balance doesn't exist
	 * @param type $subscriber
	 * @deprecated since version 4.0
	 */
	protected static function createBalanceIfMissing($aid, $sid, $billrun_key, $plan_ref) {
		Billrun_Factory::log("Customer pricing createBalanceIfMissing is deprecated method");
		$balance = Billrun_Factory::balance(array('sid' => $sid, 'billrun_key' => $billrun_key));
		if ($balance->isValid()) {
			return $balance;
		} else {
			return Billrun_Balance::createBalanceIfMissing($aid, $sid, $billrun_key, $plan_ref);
		}
	}

	/**
	 * 
	 * @param Mongodloid_Entity $rate
	 * @param string $usage_type
	 * @param Billrun_Plan $plan
	 * @todo move to plan class
	 */
	protected function isUsageUnlimited($rate, $usage_type, $plan) {
		return ($plan->isRateInBasePlan($rate, $usage_type) && $plan->isUnlimited($usage_type)) || ($plan->isRateInPlanGroup($rate, $usage_type) && $plan->isUnlimitedGroup($rate, $usage_type));
	}
	
	/**
	 * Calculates the volume granted for subscriber by rate and balance
	 * @param type $row
	 * @param type $rate
	 * @param type $balance
	 * @param type $usageType
	 */
	protected function getPrepaidGrantedVolume($row, $rate, $balance, $usageType) {
		$requestedVolume = PHP_INT_MAX;
		if (isset($row['usagev'])) {
			$requestedVolume = $row['usagev'];
		}
		if ((isset($row['billrun_pretend']) && $row['billrun_pretend']) || 
			(isset($row['free_call']) && $row['free_call'])) {
			return 0;
		}
		$maximumGrantedVolume = $this->getPrepaidGrantedVolumeByRate($rate, $usageType);
		if (isset($balance->get("balance")["totals"][$usageType]["usagev"])) {
			$currentBalanceVolume = $balance->get("balance")["totals"][$usageType]["usagev"];
		} else {
			if (isset($balance->get("balance")["totals"][$usageType]["cost"])) {
				$price = $balance->get("balance")["totals"][$usageType]["cost"];
			} else {
				$price = $balance->get("balance")["cost"];
			}
			$currentBalanceVolume = Billrun_Calculator_CustomerPricing::getVolumeByRate($rate, $usageType, abs($price));
		}
		$currentBalanceVolume = abs($currentBalanceVolume);
		
		return min(array($currentBalanceVolume, $maximumGrantedVolume, $requestedVolume));
	}
	
	/**
	 * Gets the maximum allowed granted volume for rate
	 * @param type $rate
	 * @param type $usageType
	 */
	protected function getPrepaidGrantedVolumeByRate($rate, $usageType) {
		if (isset($rate["rates"][$usageType]["prepaid_granted_usagev"])) {
			return $rate["rates"][$usageType]["prepaid_granted_usagev"];
		}
		if (isset($rate["rates"][$usageType]["prepaid_granted_cost"])) {
			return Billrun_Calculator_CustomerPricing::getVolumeByRate($rate, $usageType, $rate["rates"][$usageType]["prepaid_granted_cost"]);
		}
		
		$usagevDefault = Billrun_Factory::config()->getConfigValue("rates.prepaid_granted.$usageType.usagev", 0);
		if ($usagevDefault) {
			return $usagevDefault;
		}
		
		return Billrun_Calculator_CustomerPricing::getVolumeByRate($rate, $usageType, Billrun_Factory::config()->getConfigValue("rates.prepaid_granted.$usageType.cost", 0));
	}

}