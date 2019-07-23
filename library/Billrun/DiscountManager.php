<?php

/**
 * Discount management
 */
class Billrun_DiscountManager {

	use Billrun_Traits_ConditionsCheck;

	protected $cycle = null;
	protected $eligibleDiscounts = [];
	protected $eligibleCharges = [];
	protected static $discounts = [];
	protected static $charges = [];
	protected $subscribersDiscounts = [];
	protected static $discountsDateRangeFields = [];

	public function __construct($accountRevisions, $subscribersRevisions = [], Billrun_DataTypes_CycleTime $cycle, $params = []) {
		$this->cycle = $cycle;
		$this->prepareRevisions($accountRevisions, $subscribersRevisions);
		$this->loadEligibleDiscounts($accountRevisions, $subscribersRevisions);
		$this->loadEligibleCharges($accountRevisions, $subscribersRevisions);
	}

	/**
	 * prepare revisions for discount calculation
	 * 
	 * @param array $accountRevisions - by reference
	 * @param array $subscribersRevisions - by reference
	 */
	protected function prepareRevisions(&$accountRevisions, &$subscribersRevisions) {
		$accountRevisions = $this->getEntityRevisions($accountRevisions, 'account');
		foreach ($subscribersRevisions as &$subscriberRevisions) {
			$subscriberRevisions = $this->getEntityRevisions($subscriberRevisions, 'subscriber');
		}
	}

	/**
	 * get revisions used for discount calculation
	 * 
	 * @param array $entityRevisions
	 * @param string $type
	 * @return array
	 */
	protected function getEntityRevisions($entityRevisions, $type) {
		$ret = [];
		$dateRangeDiscoutnsFields = self::getDiscountsDateRangeFields($this->cycle->key(), $type);
		if (empty($dateRangeDiscoutnsFields)) {
			return $entityRevisions;
		}

		foreach ($entityRevisions as $entityRevision) {
			$splittedRevisions = $this->splitRevisionByFields($entityRevision, $dateRangeDiscoutnsFields);
			$ret = array_merge($ret, $splittedRevisions);
		}

		return $ret;
	}

	/**
	 * split revision to revisions by given date range fields
	 * 
	 * @param array $revision
	 * @param array $fields
	 * @return array
	 */
	protected function splitRevisionByFields($revision, $fields) {
		$ret = [];
		$revisionFrom = Billrun_Utils_Time::getTime($revision['from']);
		$revisionTo = Billrun_Utils_Time::getTime($revision['to']);
		$froms = [$revisionFrom];
		$tos = [$revisionTo];

		foreach ($fields as $field) {
			$val = Billrun_Util::getIn($revision, $field, null);
			if (is_null($val)) {
				continue;
			}

			foreach ($val as $interval) {
				$from = Billrun_Utils_Time::getTime($interval['from']);
				$to = Billrun_Utils_Time::getTime($interval['to']);

				if ($from > $revisionFrom) {
					$froms[] = $from;
				}

				if ($to < $revisionTo) {
					$tos[] = $to;
				}
			}
		}

		$intervals = array_unique(array_merge($froms, $tos));
		sort($intervals);

		for ($i = 1; $i < count($intervals); $i++) {
			$newRevision = $revision;
			$from = $intervals[$i - 1];
			$to = $intervals[$i];
			$newRevision['from'] = new MongoDate($from);
			$newRevision['to'] = new MongoDate($to);

			foreach ($fields as $field) {
				$val = Billrun_Util::getIn($newRevision, $field, null);
				if (is_null($val)) {
					continue;
				}

				$oldIntervals = $val;
				Billrun_Util::setIn($newRevision, $field, []);
				$newIntervals = [];

				foreach ($oldIntervals as $interval) {
					$intervalFrom = Billrun_Utils_Time::getTime($interval['from']);
					$intervalTo = Billrun_Utils_Time::getTime($interval['to']);
					if (($intervalFrom <= $from && $intervalTo > $from) ||
							($intervalFrom <= $to && $intervalTo > $to)) {
						$newIntervalFrom = max($from, $intervalFrom);
						$newIntervalTo = min($to, $intervalTo);
						if ($newIntervalTo > $newIntervalFrom) {
							$newIntervals[] = [
								'from' => new MongoDate($newIntervalFrom),
								'to' => new MongoDate($newIntervalTo),
							];
							break;
						}
					}
				}

				if (empty($newIntervals)) {
					Billrun_Util::unsetIn($newRevision, $field);
				} else {
					Billrun_Util::setIn($newRevision, $field, $newIntervals);
				}
			}

			$ret[] = $newRevision;
		}

		return $ret;
	}

	/**
	 * loads account's discount eligibilities
	 * 
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 */
	protected function loadEligibleDiscounts($accountRevisions, $subscribersRevisions = []) {
		$this->eligibleDiscounts = [];

		foreach (self::getDiscounts($this->cycle->key()) as $discount) {
			$eligibility = $this->getDiscountEligibility($discount, $accountRevisions, $subscribersRevisions);
			$this->setEligibility($this->eligibleDiscounts, $discount, $eligibility);
		}

		// handle subscribers' level revisions
		foreach ($subscribersRevisions as $subscriberRevisions) {
			foreach ($subscriberRevisions as $subscriberRevision) {
				$subDiscounts = Billrun_Util::getIn($subscriberRevision, 'discounts', []);
				foreach ($subDiscounts as $subDiscount) {
					$eligibility = $this->getDiscountEligibility($subDiscount, $accountRevisions, [$subscriberRevisions]);
					$this->setEligibility($this->eligibleDiscounts, $subDiscount, $eligibility);
					$this->setSubscriberDiscount($subDiscount, $this->cycle->key());
				}
			}
		}

		$this->handleConflictingDiscounts();
	}

	/**
	 * loads account's charges eligibilities
	 * 
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 */
	protected function loadEligibleCharges($accountRevisions, $subscribersRevisions = []) {
		$this->eligibleCharges = [];

		foreach (self::getCharges($this->cycle->key()) as $charge) {
			$eligibility = $this->getDiscountEligibility($charge, $accountRevisions, $subscribersRevisions);
			$this->setEligibility($this->eligibleCharges, $discount, $eligibility);
		}
	}

	/**
	 * set eligibility for discount
	 * 
	 * @param array $eligibilityEntity - array to update (by reference)
	 * @param array $discount
	 * @param array $eligibility
	 */
	protected function setEligibility(&$eligibilityEntity, $discount, $eligibility) {
		if (empty(Billrun_Util::getIn($eligibility, 'eligibility', []))) {
			return;
		}

		$discountKey = $discount['key'];
		if (isset($eligibilityEntity[$discountKey])) {
			$timeEligibility = Billrun_Utils_Time::mergeTimeIntervals(array_merge($$eligibilityEntity[$discountKey]['eligibility']), Billrun_Util::getIn($eligibility, 'eligiblity', []));

			$servicesEligibility = $eligibilityEntity[$discountKey]['services'];
			foreach (Billrun_Util::getIn($eligibility, 'services', []) as $serviceEligibility) {
				foreach ($serviceEligibility as $sid => $subServiceEligibility) {
					foreach ($subServiceEligibility as $serviceKey => $currServiceEligibility) {
						$serviceNewEligibility = Billrun_Utils_Time::mergeTimeIntervals(array_merge(Billrun_Util::getIn($eligibilityEntity, [$discountKey, 'services', $sid, $serviceKey], []), $currServiceEligibility));
						Billrun_Util::setIn($servicesEligibility, ['services', $sid, $serviceKey], $serviceNewEligibility);
					}
				}
			}

			$plansEligibility = $eligibilityEntity[$discountKey]['plans'];
			foreach (Billrun_Util::getIn($eligibility, 'plans', []) as $plansEligibility) {
				foreach ($plansEligibility as $sid => $subPlanEligibility) {
					foreach ($subPlanEligibility as $planKey => $currPlanEligibility) {
						$planNewEligibility = Billrun_Utils_Time::mergeTimeIntervals(array_merge(Billrun_Util::getIn($eligibilityEntity, [$discountKey, 'plans', $sid, $planKey], []), $currPlanEligibility));
						Billrun_Util::setIn($plansEligibility, ['plans', $sid, $planKey], $planNewEligibility);
					}
				}
			}

			$eligibility = [
				'eligibility' => $timeEligibility,
				'services' => $servicesEligibility,
				'plans' => $plansEligibility,
			];
		}

		$eligibilityEntity[$discountKey] = $eligibility;
	}

	/**
	 * fix conflicting caused by discounts with lower priority
	 */
	protected function handleConflictingDiscounts() {
		foreach ($this->eligibleDiscounts as $eligibleDiscount => $eligibilityData) {
			$discount = $this->getDiscount($eligibleDiscount, $this->cycle->key());
			foreach (Billrun_Util::getIn($discount, 'excludes', []) as $discountToExclude) {
				if (isset($this->eligibleDiscounts[$discountToExclude])) {
					$this->eligibleDiscounts[$discountToExclude]['eligibility'] = Billrun_Utils_Time::getIntervalsDifference($this->eligibleDiscounts[$discountToExclude]['eligibility'], $eligibilityData['eligibility']);
					if (empty($this->eligibleDiscounts[$discountToExclude]['eligibility'])) {
						unset($this->eligibleDiscounts[$discountToExclude]);
					}

					foreach ($this->eligibleDiscounts[$discountToExclude]['services'] as $sid => $services) {
						foreach ($services as $serviceKey => $serviceEligibility) {
							$this->eligibleDiscounts[$discountToExclude]['services'][$sid][$serviceKey] = Billrun_Utils_Time::getIntervalsDifference($serviceEligibility, $eligibilityData['eligibility']);
							if (empty($this->eligibleDiscounts[$discountToExclude]['services'][$sid][$serviceKey])) {
								unset($this->eligibleDiscounts[$discountToExclude]['services'][$sid][$serviceKey]);
							}
						}

						if (empty($this->eligibleDiscounts[$discountToExclude]['services'][$sid])) {
							unset($this->eligibleDiscounts[$discountToExclude]['services'][$sid]);
						}
					}
				}
			}
		}
	}

	/**
	 * Get eligible discounts for account
	 * 
	 * @return array - array of discounts for the account
	 */
	public function getEligibleDiscounts($discountsOnly = false) {
		if ($discountsOnly) {
			return $this->eligibleDiscounts;
		}

		return array_merge($this->eligibleDiscounts, $this->eligibleCharges);
	}

	/**
	 * get all active discounts in the system
	 * uses internal static cache
	 * 
	 * @param unixtimestamp $time
	 * @param array $query
	 * @return array
	 */
	public static function getDiscounts($billrunKey, $query = []) {
		if (empty(self::$discounts[$billrunKey])) {
			$basicQuery = [
				'params' => [
					'$exists' => 1,
				],
				'from' => [
					'$lte' => new MongoDate(Billrun_Billingcycle::getEndTime($billrunKey)),
				],
				'to' => [
					'$gte' => new MongoDate(Billrun_Billingcycle::getStartTime($billrunKey)),
				],
			];

			$sort = [
				'priority' => -1,
				'to' => -1,
			];

			$discountColl = Billrun_Factory::db()->discountsCollection();
			$loadedDiscounts = $discountColl->query(array_merge($basicQuery, $query))->cursor()->sort($sort);
			self::$discounts = [];

			foreach ($loadedDiscounts as $discount) {
				if (isset(self::$discounts[$billrunKey][$discount['key']]) &&
						self::$discounts[$billrunKey][$discount['key']]['from'] == $discount['to']) {
					self::$discounts[$billrunKey][$discount['key']]['from'] = $discount['from'];
				} else {
					self::$discounts[$billrunKey][$discount['key']] = $discount;
				}
			}
		}

		return self::$discounts[$billrunKey];
	}

	/**
	 * get all active charges in the system
	 * uses internal static cache
	 * 
	 * @param unixtimestamp $time
	 * @param array $query
	 * @return array
	 */
	public static function getCharges($billrunKey, $query = []) {
		if (empty(self::$charges[$billrunKey])) {
			$basicQuery = [
				'params' => [
					'$exists' => 1,
				],
				'from' => [
					'$lte' => new MongoDate(Billrun_Billingcycle::getEndTime($billrunKey)),
				],
				'to' => [
					'$gte' => new MongoDate(Billrun_Billingcycle::getStartTime($billrunKey)),
				],
			];

			$sort = [
				'priority' => -1,
				'to' => -1,
			];

			$chargesColl = Billrun_Factory::db()->chargesCollection();
			$loadedCharges = $chargesColl->query(array_merge($basicQuery, $query))->cursor()->sort($sort);
			self::$charges = [];

			foreach ($loadedCharges as $charge) {
				if (isset(self::$charges[$billrunKey][$charge['key']]) &&
						self::$charges[$billrunKey][$charge['key']]['from'] == $charge['to']) {
					self::$charges[$billrunKey][$charge['key']]['from'] = $charge['from'];
				} else {
					self::$charges[$billrunKey][$charge['key']] = $charge;
				}
			}
		}

		return self::$charges[$billrunKey];
	}

	/**
	 * manually set discounts
	 * 
	 * @param array $discounts
	 */
	public static function setDiscounts($discounts, $billrunKey) {
		self::$discounts[$billrunKey] = [];
		usort($discounts, function ($a, $b) {
			return Billrun_Util::getIn($b, 'priority', 0) > Billrun_Util::getIn($a, 'priority', 0);
		});

		foreach ($discounts as $discount) {
			if (!$discount instanceof Mongodloid_Entity) {
				$discount = new Mongodloid_Entity($discount);
			}
			self::$discounts[$billrunKey][$discount['key']] = $discount;
		}
	}

	/**
	 * manually set subscriber discount
	 * 
	 * @param array $discount
	 * @param string $billrunKey
	 */
	protected function setSubscriberDiscount($discount, $billrunKey) {
		$this->subscribersDiscounts[$billrunKey][$discount['key']] = $discount;
	}

	/**
	 * get discount object by key
	 * 
	 * @param string $discountKey
	 * @param string $billrunKey
	 * @return discount object if found, false otherwise
	 */
	public function getDiscount($discountKey, $billrunKey) {
		if (isset(self::$discounts[$billrunKey][$discountKey])) {
			return self::$discounts[$billrunKey][$discountKey];
		}

		if (isset($this->subscribersDiscounts[$billrunKey][$discountKey])) {
			return $this->subscribersDiscounts[$billrunKey][$discountKey];
		}

		return false;
	}

	/**
	 * get charge object by key
	 * 
	 * @param string $chargeKey
	 * @param string $billrunKey
	 * @return charge object if found, false otherwise
	 */
	public function getCharge($chargeKey, $billrunKey) {
		if (isset(self::$charges[$billrunKey][$chargeKey])) {
			return self::$charges[$billrunKey][$chargeKey];
		}

		return false;
	}

	/**
	 * get all date range fields used by discount for the given $type
	 * uses internal static cache
	 * 
	 * @param string $billrunKey
	 * @param string $type
	 * @return array
	 */
	public static function getDiscountsDateRangeFields($billrunKey, $type) {
		if (empty(self::$discountsDateRangeFields[$billrunKey][$type])) {
			self::$discountsDateRangeFields[$billrunKey][$type] = [];
			foreach (self::getDiscounts($billrunKey) as $discount) {
				foreach (Billrun_Util::getIn($discount, ['params', 'conditions'], []) as $condition) {
					if (!isset($condition[$type])) {
						continue;
					}

					$typeConditions = Billrun_Util::getIn($condition, $type, []);
					if (Billrun_Util::isAssoc($typeConditions)) { // handle account/subscriber structure
						$typeConditions = [$typeConditions];
					}

					foreach ($typeConditions as $typeCondition) {
						foreach (Billrun_Util::getIn($typeCondition, 'fields', []) as $field) {
							if (in_array($field['value'], ['active', 'notActive'])) {
								self::$discountsDateRangeFields[$billrunKey][$type][] = $field['field'];
							}
						}
					}
				}
			}

			self::$discountsDateRangeFields[$billrunKey][$type] = array_unique(self::$discountsDateRangeFields[$billrunKey][$type]);
		}

		return self::$discountsDateRangeFields[$billrunKey][$type];
	}

	/**
	 * Get sorted time intervals when the account is eligible for the given discount 
	 * 
	 * @param array $conditions
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 * @return array of intervals
	 */
	protected function getDiscountEligibility($discount, $accountRevisions, $subscribersRevisions = []) {
		$discountFrom = max(Billrun_Utils_Time::getTime($discount['from']), $this->cycle->start());
		$discountTo = min(Billrun_Utils_Time::getTime($discount['to']), $this->cycle->end());
		$conditions = Billrun_Util::getIn($discount, 'params.conditions', []);
		if (empty($conditions)) { // no conditions means apply to all entities
			return [
				'eligibility' => [
					[
						'from' => $discountFrom,
						'to' => $discountTo,
					],
				],
			];
		}

		$minSubscribers = Billrun_Util::getIn($discount, 'params.min_subscribers', 1);
		$maxSubscribers = Billrun_Util::getIn($discount, 'params.max_subscribers', null);
		$cycles = Billrun_Util::getIn($discount, 'params.cycles', null);
		$eligibility = [];
		$servicesEligibility = [];
		$plansEligibility = [];

		if (count($subscribersRevisions) < $minSubscribers) { // skip conditions check if there are not enough subscribers
			return false;
		}

		$params = [
			'min_subscribers' => $minSubscribers,
			'max_subscribers' => $maxSubscribers,
			'cycles' => $cycles,
		];

		foreach ($conditions as $condition) { // OR logic
			$conditionEligibility = $this->getConditionEligibility($condition, $accountRevisions, $subscribersRevisions, $params);

			if (empty($conditionEligibility) || empty($conditionEligibility['eligibility'])) {
				continue;
			}

			$eligibility = array_merge($eligibility, $conditionEligibility['eligibility']);

			foreach ($conditionEligibility['services'] as $sid => $subServicesEligibility) {
				if (isset($servicesEligibility[$sid])) {
					$servicesEligibility[$sid] = array_merge($servicesEligibility[$sid], $subServicesEligibility);
				} else {
					$servicesEligibility[$sid] = $subServicesEligibility;
				}
			}

			foreach ($conditionEligibility['plans'] as $sid => $subPlansEligibility) {
				if (isset($plansEligibility[$sid])) {
					$plansEligibility[$sid] = array_merge($plansEligibility[$sid], $subPlansEligibility);
				} else {
					$plansEligibility[$sid] = $subPlansEligibility;
				}
			}
		}

		$eligibility = $this->getFinalEligibility($eligibility, $discountFrom, $discountTo);

		foreach ($servicesEligibility as &$subServicesEligibility) {
			foreach ($subServicesEligibility as &$subServiceEligibility) {
				$subServiceEligibility = $this->getFinalEligibility($subServiceEligibility, $discountFrom, $discountTo);
			}
		}

		foreach ($plansEligibility as &$subPlansEligibility) {
			foreach ($subPlansEligibility as &$subPlanEligibility) {
				$subPlanEligibility = $this->getFinalEligibility($subPlanEligibility, $discountFrom, $discountTo);
			}
		}

		return [
			'eligibility' => $eligibility,
			'services' => $servicesEligibility,
			'plans' => $plansEligibility,
		];
	}

	/**
	 * fix eligibility to be best represents by intervals + align from/to according to discount's from/to
	 * 
	 * @param array $eligibility
	 * @param unixtimestamp $discountFrom
	 * @param unixtimestamp $discountTo
	 * @return array
	 */
	protected function getFinalEligibility($eligibility, $discountFrom, $discountTo) {
		$finalEligibility = Billrun_Utils_Time::mergeTimeIntervals($eligibility);

		foreach ($finalEligibility as $i => &$eligibilityInterval) {
			// limit eligibility to discount revision (from/to)
			if ($eligibilityInterval['from'] < $discountFrom) {
				if ($eligibilityInterval['to'] <= $discountFrom) {
					unset($eligibility[$i]);
				} else {
					$eligibilityInterval['from'] = $discountFrom;
				}
			}
			if ($eligibilityInterval['to'] > $discountTo) {
				if ($eligibilityInterval['from'] >= $discountTo) {
					unset($eligibility[$i]);
				} else {
					$eligibilityInterval['to'] = $discountTo;
				}
			}
		}

		return $finalEligibility;
	}

	/**
	 * Get time intervals when the given condition is met for the account
	 * 
	 * @param array $conditions
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 * @param array $params
	 * @return array of intervals
	 */
	protected function getConditionEligibility($condition, $accountRevisions, $subscribersRevisions = [], $params = []) {
		$accountEligibility = [];
		$subsEligibility = [];
		$servicesEligibility = [];
		$plansEligibility = [];
		$minSubscribers = $params['min_subscribers'] ?? 1;
		$maxSubscribers = $params['max_subscribers'] ?? null;
		$cycles = $params['cycles'] ?? null;

		$accountConditions = Billrun_Util::getIn($condition, 'account.fields', []);

		if (empty($accountConditions)) {
			$accountEligibility[] = $this->getAllCycleInterval();
		} else {
			$accountEligibility = $this->getAccountEligibility($accountConditions, $accountRevisions);
			if (empty($accountEligibility)) {
				return false; // account conditions must match
			}
			$accountEligibility = Billrun_Utils_Time::mergeTimeIntervals($accountEligibility);
		}

		$subscribersConditions = Billrun_Util::getIn($condition, 'subscriber.0.fields', []); // currently supports 1 condtion's type
		$subscribersServicesConditions = Billrun_Util::getIn($condition, 'subscriber.0.service.any', []); // currently supports 1 condtion's type
		$hasPlanConditions = $this->hasPlanCondition($subscribersConditions);
		$hasServiceConditions = $this->hasServicesCondition($subscribersServicesConditions);

		foreach ($subscribersRevisions as $subscriberRevisions) {
			$sid = $subscriberRevisions[0]['sid'];
			if (empty($subscribersConditions) && empty($subscribersServicesConditions)) {
				$subsEligibility[$sid] = [
					$this->getAllCycleInterval(),
				];
			} else {
				$subCycles = $hasServiceConditions ? null : $cycles; // in case of services conditions, will check as part of services eligibility
				$subEligibilityRet = $this->getSubscriberEligibility($subscribersConditions, $subscriberRevisions, $subCycles);
				$subEligibility = $subEligibilityRet['eligibility'];
				if (empty($subEligibility)) {
					continue; // if the current subscriber does not match, check other subscribers
				}

				$subPlansEligibility = Billrun_Util::getIn($subEligibilityRet, 'plans', []);
				if (!empty($subPlansEligibility)) {
					$plansEligibility[$sid] = $subPlansEligibility;
				}

				if (!empty($subscribersServicesConditions)) {
					$subServicesEligibility = $this->getServicesEligibility($subscribersServicesConditions, $subscriberRevisions, $hasPlanConditions, $cycles);
					$servicesEligibilityIntervals = Billrun_Util::getIn($subServicesEligibility, 'eligibility', []);
					if (empty($servicesEligibilityIntervals)) {
						continue; // if the current subscriber's services does not match, check other subscribers
					}

					$subEligibility = Billrun_Utils_Time::getIntervalsIntersections($subEligibility, $servicesEligibilityIntervals); // reduce subscriber eligibility to services eligibility intersection
					$servicesEligibility[$sid] = Billrun_Util::getIn($subServicesEligibility, 'services', []);
				}

				$subsEligibility[$sid] = Billrun_Utils_Time::mergeTimeIntervals($subEligibility);
			}
		}

		$totalEligibility = [];
		$eligibilityBySubs = [];

		// goes only over accout's eligibility because it must met
		foreach ($accountEligibility as $accountEligibilityInterval) {
			// check eligibility day by day
			for ($day = $accountEligibilityInterval['from']; $day < $accountEligibilityInterval['to']; $day = strtotime('+1 day', $day)) {
				$eligibleSubsInDay = [];
				$dayFrom = strtotime('midnight', $day);
				$dayTo = strtotime('+1 day', $dayFrom);
				foreach ($subsEligibility as $sid => $subEligibility) {
					foreach ($subEligibility as $subEligibilityIntervals) {
						if ($subEligibilityIntervals['from'] <= $day && $subEligibilityIntervals['to'] > $day) {
							$eligibleSubsInDay[] = $sid;

							if (!is_null($maxSubscribers) && count($eligibleSubsInDay) > $maxSubscribers) { // passed max subscribers in current day
								continue 3; // check next day
							}

							continue 2; // check next subscriber
						}

						if ($subEligibilityIntervals['from'] > $day) {
							continue 2; // intervals are sorted, check next subscriber
						}
					}
				}

				if (count($eligibleSubsInDay) >= $minSubscribers) { // account is eligible for the discount in current day
					$totalEligibility[] = [
						'from' => $dayFrom,
						'to' => $dayTo,
					];

					foreach ($eligibleSubsInDay as $eligibleSubInDay) {
						if (empty($eligibilityBySubs[$eligibleSubInDay])) {
							$eligibilityBySubs[$eligibleSubInDay] = [];
						}
						$eligibilityBySubs[$eligibleSubInDay][] = [
							'from' => $dayFrom,
							'to' => $dayTo,
						];
					}
				}
			}
		}

		foreach ($servicesEligibility as $sid => &$subServicesEligibility) {
			foreach ($subServicesEligibility as $service => &$serviceEligibility) {
				$serviceEligibility = Billrun_Utils_Time::getIntervalsIntersections($serviceEligibility, $eligibilityBySubs[$sid]);
			}
		}

		foreach ($plansEligibility as $sid => &$subPlansEligibility) {
			foreach ($subPlansEligibility as $plan => &$planEligibility) {
				$planEligibility = Billrun_Utils_Time::getIntervalsIntersections($planEligibility, $eligibilityBySubs[$sid]);
			}
		}

		return [
			'eligibility' => Billrun_Utils_Time::mergeTimeIntervals($totalEligibility),
			'services' => $servicesEligibility,
			'plans' => $plansEligibility,
		];
	}

	/**
	 * get array of intervals on which the account meets the conditions
	 * 
	 * @param array $conditions
	 * @param array $entityRevisions
	 * @return array of intervals
	 */
	protected function getAccountEligibility($conditions, $accountRevisions) {
		$eligibility = [];
		foreach ($accountRevisions as $accountRevision) {
			$from = Billrun_Utils_Time::getTime($accountRevision['from']);
			$to = Billrun_Utils_Time::getTime($accountRevision['to']);

			if ($this->isConditionsMeet($accountRevision, $conditions)) {
				if ($from < $to) {
					$eligibility[] = [
						'from' => $from,
						'to' => $to,
					];
				}
			}
		}

		return $eligibility;
	}

	/**
	 * get array of intervals on which the entity meets the conditions
	 * 
	 * @param array $conditions
	 * @param array $entityRevisions
	 * @param int $cycles
	 * @return array of intervals
	 */
	protected function getSubscriberEligibility($conditions, $subscriberRevisions, $cycles = null) {
		$eligibility = [];
		$plansEligibility = [];
		$hasPlansConditions = $this->hasPlanCondition($conditions);

		foreach ($subscriberRevisions as $subscriberRevision) {
			$cyclesEligibilityEnd = !is_null($cycles) ? strtotime("+{$cycles} months", Billrun_Utils_Time::getTime($subscriberRevision['plan_activation'])) : null;

			$from = Billrun_Utils_Time::getTime($subscriberRevision['from']);
			$to = Billrun_Utils_Time::getTime($subscriberRevision['to']);

			if (!is_null($cyclesEligibilityEnd) && $cyclesEligibilityEnd <= $from) {
				continue;
			}

			if ($this->isConditionsMeet($subscriberRevision, $conditions)) {
				if (!is_null($cyclesEligibilityEnd) && $cyclesEligibilityEnd < $to) {
					$to = $cyclesEligibilityEnd;
				}

				if ($from < $to) {
					$eligibility[] = [
						'from' => $from,
						'to' => $to,
					];

					if ($hasPlansConditions) {
						$plan = $subscriberRevision['plan'];
						if (empty($plansEligibility[$plan])) {
							$plansEligibility[$plan] = [];
						}
						$plansEligibility[$plan][] = [
							'from' => $from,
							'to' => $to,
						];
					}
				}
			}
		}

		return [
			'eligibility' => $eligibility,
			'plans' => $plansEligibility,
		];
	}

	/**
	 * get array of intervals on which the entity meets the conditions
	 * 
	 * @param array $conditions
	 * @param array $subscriberRevisions
	 * @param bool $hasPlanConditions
	 * @param int $cycles
	 * @return array of intervals
	 */
	protected function getServicesEligibility($conditions, $subscriberRevisions, $hasPlanConditions = false, $cycles = null) {
		$eligibility = null;
		$servicesEligibility = [];

		foreach ($conditions as $condition) { // AND logic
			$conditionEligibility = [];
			$conditionFields = Billrun_Util::getIn($condition, 'fields', []);
			foreach ($subscriberRevisions as $subscriberRevision) { // OR logic
				if (empty($conditionFields)) {
					$conditionEligibility[] = [
						'from' => Billrun_Utils_Time::getTime($subscriberRevision['from']),
						'to' => Billrun_Utils_Time::getTime($subscriberRevision['to']),
					];
					continue;
				}
				if ($hasPlanConditions && !is_null($cycles)) {
					$planEligibilityEnd = strtotime("+{$cycles} months", Billrun_Utils_Time::getTime($subscriberRevision['plan_activation']));
				} else {
					$planEligibilityEnd = null;
				}

				foreach (Billrun_Util::getIn($subscriberRevision, 'services', []) as $subscriberService) { // OR logic
					$serviceFrom = max(Billrun_Utils_Time::getTime($subscriberRevision['from']), Billrun_Utils_Time::getTime($subscriberService['from']));
					$serviceTo = min(Billrun_Utils_Time::getTime($subscriberRevision['to']), Billrun_Utils_Time::getTime($subscriberService['to']));
					if (!is_null($cycles)) {
						$serviceEligibilityEnd = strtotime("+{$cycles} months", Billrun_Utils_Time::getTime($subscriberService['service_activation']));
						if (!is_null($planEligibilityEnd)) {
							$serviceEligibilityEnd = max($planEligibilityEnd, $serviceEligibilityEnd);
						}

						if ($serviceEligibilityEnd < $serviceFrom) {
							continue 2;
						}

						if ($serviceEligibilityEnd < $serviceTo) {
							$serviceTo = $serviceEligibilityEnd;
						}
					}
					if ($this->isConditionsMeet($subscriberService, $conditionFields)) {
						if ($serviceFrom < $serviceTo) {
							$conditionEligibility[] = [
								'from' => $serviceFrom,
								'to' => $serviceTo,
							];
							if (empty($servicesEligibility[$subscriberService['key']])) {
								$servicesEligibility[$subscriberService['key']] = [];
							}
							$servicesEligibility[$subscriberService['key']][] = [
								'from' => $serviceFrom,
								'to' => $serviceTo,
							];
						}
					}
				}
			}

			if (empty($conditionEligibility)) { // one of the conditions does not meet
				return [
					'eligibility' => [],
					'services' => [],
				];
			}

			if (is_null($eligibility)) { // empty is not good enough because intersection might cause empty array
				$eligibility = $conditionEligibility;
			} else {
				$eligibility = Billrun_Utils_Time::getIntervalsIntersections($eligibility, $conditionEligibility);
			}
		}

		$eligibility = Billrun_Utils_Time::mergeTimeIntervals($eligibility);

		foreach ($servicesEligibility as &$serviceEligibility) {
			$serviceEligibility = Billrun_Utils_Time::getIntervalsIntersections($eligibility, $serviceEligibility);
			$serviceEligibility = Billrun_Utils_Time::mergeTimeIntervals($serviceEligibility);
		}

		return [
			'eligibility' => $eligibility,
			'services' => $servicesEligibility,
		];
	}

	/**
	 * checks if conditions set has a condition on plan
	 * 
	 * @param array $conditions
	 * @return boolean
	 */
	protected function hasPlanCondition($conditions) {
		foreach ($conditions as $condition) {
			if (in_array($condition['field'], ['plan', 'plan_activation', 'plan_deactivation'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * checks if conditions set has a condition on service
	 * 
	 * @param array $conditions
	 * @return boolean
	 */
	protected function hasServicesCondition($conditions) {
		foreach ($conditions as $condition) {
			if (!empty(Billrun_Util::getIn($condition, 'fields', []))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * gets intervals covers entire cycle
	 * 
	 * @return array
	 */
	protected function getAllCycleInterval() {
		return [
			'from' => $this->cycle->start(),
			'to' => $this->cycle->end(),
		];
	}

	/**
	 * see Billrun_Traits_ValueTranslator::getTranslationMapping
	 */
	public function getTranslationMapping($params = []) {
		return [
			'@cycle_end_date@' => [
				'hard_coded' => new MongoDate($this->cycle->end()),
			],
			'@cycle_start_date@' => [
				'hard_coded' => new MongoDate($this->cycle->start()),
			],
			'@plan_activation@' => [
				'field' => 'plan_activation',
			],
			'@plan_deactivation@' => [
				'field' => 'plan_deactivation',
			],
			'@activation_date@' => [
				'field' => 'activation_date',
			],
			'@deactivation_date@' => [
				'field' => 'deactivation_date',
			],
		];
	}

	/**
	 * generate CDRs based on discount's eligibility and given flat lines
	 * 
	 * @param array $lines
	 * @return array
	 */
	public function generateCdrs($lines) {
		$cdrs = [];
		
		if (empty($lines) || empty($eligibleDiscounts = $this->getEligibleDiscounts())) {
			return $cdrs;
		}

		foreach (['discounts' => $this->eligibleDiscounts, 'charge' => $this->eligibleCharges] as $type => $eligibilities) {
			foreach ($eligibilities as $key => $eligibility) { // discounts/charges are ordered by priority
				$entity = $type == 'charge' ? $this->getCharge($key, $this->cycle->key()) : $this->getDiscount($key, $this->cycle->key());
				if (!$entity) {
					Billrun_Factory::log("Cannot get '{$key}', CDR was not generated", Billrun_Log::ERR);
					continue;
				}

				$currentCdrs = $this->generateDiscountCdrs($type, $lines, $entity, $eligibility);
				if ($currentCdrs) {
					$cdrs = array_merge($cdrs, $currentCdrs);
				}
			}
		}

		return $cdrs;
	}
	
	/**
	 * generate CDRs for 1 discount/charge
	 * 
	 * @param string $type
	 * @param array $lines
	 * @param array $discount
	 * @param array $eligibility
	 * @return array
	 */
	protected function generateDiscountCdrs($type, $lines, $discount, $eligibility) {
		$cdrs = [];
		$discountedAmount = 0;
		
		if ($type == 'charge' && $discount['type'] == 'monetary') { // monetary charge's subject can only be general
			$chargeAmount = Billrun_Util::getIn($discount, 'subject.general.value', 0);
			if ($chargeAmount > 0) {
				$cdrs[] = $this->generateCdr($type, $discount, $chargeAmount);
			}
			return $cdrs;
		}
		
		$amountLimit = Billrun_Util::getIn($discount, 'limit', PHP_INT_MAX);
		
		foreach ($lines as $line) {
			$discountedLineAmount = 0;
			$lineAmountLimit = $line['full_price'];
			$lineEligibility = $this->getLineEligibility($line, $discount, $eligibility);
			if (empty($lineEligibility)) {
				continue;
			}
			
			foreach ($lineEligibility as $eligibilityInterval) {
				$from = $eligibilityInterval['from'];
				$to = $eligibilityInterval['to'];
				$addToCdr = [
					'discount_from' => new MongoDate($from),
					'discount_to' => new MongoDate($to),
				];
				$discountAmount = $eligibilityInterval['amount'];

				if (($discountedAmount + $discountAmount > $amountLimit) ||
						($discountedLineAmount + $discountAmount > $lineAmountLimit)) { // current discount reached limit
					$addToCdr['orig_discount_amount'] = -$discountAmount;
					$discountAmount = min($amountLimit - $discountedAmount, $lineAmountLimit - $discountedAmount);
				}
				
				if ($discountAmount > 0) {
					$cdrs[] = $this->generateCdr($type, $discount, $discountAmount, $line, $addToCdr);
				}
				
				$discountedAmount += $discountAmount;
				if ($discountedAmount >= $amountLimit) { // discount reached amount limit
					return $cdrs;
				}
				
				$discountedLineAmount += $discountAmount;
				if ($discountedLineAmount >= $lineAmountLimit) { // discount exceeds line price
					continue 2;
				}
			}
		}
		
		return $cdrs;
	}
	
	/**
	 * get line's discount eligibility
	 * 
	 * @param array $line
	 * @param array $discount
	 * @param array  $eligibility
	 * @return array
	 */
	protected function getLineEligibility($line, $discount, $eligibility) {
		$ret = [];
		$lineEligibility = $this->getLineFullEligibility($line);
		$valuesEligibility = $this->getLineValueEligibility($line, $discount, $eligibility);
		
		foreach ($valuesEligibility as $valueEligibility) {
			$value = $valueEligibility['value'];
			$currValueEligibility = Billrun_Utils_Time::getIntervalsIntersections($lineEligibility, $valueEligibility['eligibility']);
			$currValueEligibility = Billrun_Utils_Time::getIntervalsDifference($currValueEligibility, $ret);
			foreach ($currValueEligibility as $currValueEligibilityInterval) {
				$from = $currValueEligibilityInterval['from'];
				$to = $currValueEligibilityInterval['to'];
				if ($from < $to) {
					$ret[] = [
						'from' => $from,
						'to' => $to,
						'amount' => $this->calculateDiscountAmount($discount, $line, $value, $from, $to),
					];
				}
			}
		}
		
		return $ret;
	}
	
	/**
	 * get line's eligibility for values (based on discount's subject field)
	 * for every value - returns the eligibility intervals
	 * 
	 * @param array $line
	 * @param array $discount
	 * @param array $eligibility
	 * @return array
	 */
	protected function getLineValueEligibility($line, $discount, $eligibility) {
		$ret = [];

		$type = $this->getLineType($line);
		$key = $line[$type]; // plan/service name
		
		// specific plan/service
		$specificValue = Billrun_Util::getIn($discount, ['subject', $type, $key, 'value'], 0);
		if ($specificValue > 0) {
			$ret[] = [
				'value' => $specificValue,
				'eligibility' => $this->getLineFullEligibility($line),
			];
		}
		
		// plan/service found for eligibility
		$eligibilityType = $type == 'plan' ? 'plans' : 'services';
		$matchedEligibility = Billrun_Util::getIn($eligibility, [$eligibilityType, $line['sid'], $key], []);
		$matchedValue = Billrun_Util::getIn($discount, ['subject', "matched_{$eligibilityType}", 'value'], 0);
		if (!empty($matchedEligibility) && $matchedValue > 0) {
			$ret[] = [
				'value' => $matchedValue,
				'eligibility' => $matchedEligibility,
			];
		}
		
		// monthly fees (fallback)
		$monthlyFeesValue = Billrun_Util::getIn($discount, ['subject', 'monthly_fees', 'value'], 0);
		if ($monthlyFeesValue > 0) {
			$ret[] = [
				'value' => $monthlyFeesValue,
				'eligibility' => $this->getLineFullEligibility($line),
			];
		}
		
		usort($ret, function($a, $b) {
			return $a['value'] < $b['value'];
		});
		
		return $ret;
	}
	
	/**
	 * calculates disocunt's monetary amount
	 * 
	 * @param array $discount
	 * @param array $line
	 * @param array $value
	 * @param unixtimestamp $from
	 * @param unixtimestamp $to
	 * @return float
	 */
	protected function calculateDiscountAmount($discount, $line, $value, $from, $to) {
		if (Billrun_Util::getIn($discount, 'type', 'percentage') === 'percentage') {
			$amount = $line['full_price'] * $value;
		} else {
			$amount = $value;
		}
		
		if ($this->isDiscountProrated($discount, $line)) {
			$discountDays = Billrun_Utils_Time::getDaysDiff($from, $to);
			$cycleDays = $this->cycle->days();
			if ($discountDays > 0 && $discountDays < $cycleDays) {
				$amount *= ($discountDays / $cycleDays);
			}
		}
		
		return $amount;
	}
	
	/**
	 * whether or not the discount is prorated
	 * 
	 * @param array $discount
	 * @param array $line
	 * @return boolean
	 */
	protected function isDiscountProrated($discount, $line) {
		$proration = Billrun_Util::getIn($discount, 'proration', 'inherited');
		if ($proration === 'no') {
			return false;
		}
		
		return (isset($line['start']) && (Billrun_Utils_Time::getTime($line['start']) != $this->cycle->start())) ||
			(isset($line['end']) && (Billrun_Utils_Time::getTime($line['end']) != $this->cycle->end()));
	}
	
	/**
	 * get line's type (service/plan)
	 * 
	 * @param array $line
	 * @return string
	 */
	protected function getLineType($line) {
		return $line['type'] == 'service' ? 'service' : 'plan';
	}
	
	/**
	 * get maximum interval for line in the cycle
	 * 
	 * @param array $line
	 * @return array
	 */
	protected function getLineFullEligibility($line) {
		return [
			[
				'from' => isset($line['start']) ? Billrun_Utils_Time::getTime($line['start']) : $this->cycle->start(),
				'to' => isset($line['end']) ? Billrun_Utils_Time::getTime($line['end']) : $this->cycle->end(),
			],
		];
	}

	/**
	 * Generate a single discount/charge CDR
	 */
	protected function generateCdr($type, $discount, $discountAmount, $eligibleLine = [], $addToCdr = []) {
		$isChargeLine = $type === 'charge';
		$collection = $isChargeLine ? Billrun_Factory::db()->chargesCollection() : Billrun_Factory::db()->discountsCollection();
		$discountLine = array(
			'key' => $discount['key'],
			'name' => $discount['description'],
			'type' => 'credit',
			'description' => $discount['description'],
			'usaget' =>  $isChargeLine ? 'conditional_charge' : 'discount',
			'discount_type' => isset($discount['type']) ? $discount['type'] : 'percentage',
			'urt' => new MongoDate($this->cycle->end()),
			'process_time' => new MongoDate(),
			'arate' => $discount->createRef($collection),
			'arate_key' => $discount['key'],
			'aid' => $eligibleLine['aid'],
			'sid' => $eligibleLine['sid'],
			'source' => 'billrun',
			'billrun' => $eligibleLine['billrun'],
			'usagev' => 1,
			'aprice' => $isChargeLine ? $discountAmount : -$discountAmount,
		);
		
		if (!empty($eligibleLine)) {
			$discountLine['eligible_line'] = $eligibleLine['stamp'];
		}
		
		$discountLine = $this->addTaxationData($discountLine);
		
		$discountLine = array_merge($discountLine, $addToCdr);
		return $discountLine;
	}
	
	/**
	 * add taxation data to the given line
	 * 
	 * @param array $line
	 * @return array
	 */
	protected function addTaxationData(&$line) {
		$taxCalc = Billrun_Calculator::getInstance(['autoload' => false, 'type' => 'tax']);
		return $taxCalc->updateRow($line);
	}

}
