<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator class for SMS records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Sms extends Billrun_Calculator_Base_Rate {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'sms';

	/**
	 * method to get calculator lines
	 */
	protected function getLines() {
		$lines = Billrun_Factory::db()->linesCollection();

		return $lines->query(array('$or' => array(
						array('type' => array('$in' => array('smpp')), 'record_type' => '1', "cause_of_terminition" => "100", 'calling_number' => array('$in' => array('000000000002020', '000000000006060', '000000000007070'))),
						array('type' => array('$in' => array('smsc')), 'record_type' => '1', 'calling_msc' => array('$ne' => '000000000000000'), "cause_of_terminition" => "100"),
						array('type' => array('$in' => array('mmsc')), 'action' => array('$in' => array('S')), 'final_state' => 'S', 'mm_source_addr' => array('$regex' => '^\+\d+\/TYPE\s*=\s*.*golantelecom')),
					),
					$this->ratingField => array('$exists' => false)))
				->cursor()->limit($this->limit);
	}

	/**
	 * Write the calculation into DB.
	 * @param $row the line CDR to update. 
	 */
	protected function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array('row' => $row));

		$current = $row->getRawData();
		$usage_type = $this->getLineUsageType($row);
		$volume = $this->getLineVolume($row, $usage_type);
		$rate = $this->getLineRate($row, $usage_type);
		$added_values = array(
			'usaget' => $usage_type,
			'usagev' => $volume,
			$this->ratingField => ($rate ? $rate['_id'] : FALSE),
		);
		$newData = array_merge($current, $added_values);
		$row->setRawData($newData);
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array('row' => $row));
	}

	protected function getLineVolume($row, $usage_type) {
		return 1;
	}

	protected function getLineUsageType($row) {
		return $row['type'] == 'mmsc' ? 'mms' : 'sms';
	}

	/**
	 * Identify if the row belong to calculator
	 * 
	 * @return boolean true if the row identify as belonging to the calculator, else false
	 */
	protected function identify($row) {
		return true;
	}

	/**
	 * Get the rate the best fit a given line
	 * @param type $row the line to get the rate for.
	 * @return Array 
	 */
	protected function getLineRate($row, $usage_type) {
		$called_number = preg_replace('/[^\d]/', '', preg_replace('/^0+/', '', ($row['type'] != 'mmsc' ? $row['called_msc'] : $row['recipent_addr'])));
		$line_time = $row['unified_record_time'];

		$rates = Billrun_Factory::db()->ratesCollection();
		//Billrun_Factory::log()->log("row : ".print_r($row ,1),  Zend_Log::DEBUG);
//		$type = $row['type'] == 'mmsc' ? 'mms' : 'sms';
		$called_number_prefixes = $this->getPrefixes($called_number);
		//Billrun_Factory::log()->log("prefixes  for $called_number : ".print_r($called_number_prefixes ,1),  Zend_Log::DEBUG);
		$base_match = array(
			'$match' => array(
				'params.prefix' => array(
					'$in' => $called_number_prefixes,
				),
				"rates.$usage_type" => array(
					'$exists' => true
				),
				"from" => array(
					'$lte' => $line_time
				),
				"to" => array(
					'$gte' => $line_time
				),
			)
		);

		$unwind = array(
			'$unwind' => '$params.prefix',
		);

		$sort = array(
			'$sort' => array(
				'params.prefix' => -1,
			),
		);

		$match2 = array(
			'$match' => array(
				'params.prefix' => array(
					'$in' => $called_number_prefixes,
				),
			)
		);

		$matched_rates = $rates->aggregate($base_match, $unwind, $sort, $match2);
		//Billrun_Factory::log()->log("rates : ".print_r($matched_rates ,1),  Zend_Log::DEBUG);
		if (empty($matched_rates)) {
			return FALSE;
		}

		return reset($matched_rates);
	}
	/**
	 * Get an array of prefixes for a given number.
	 * @param type $str the number to get  prefixes to.
	 * @return Array the possible prefixes of the number.
	 */
	protected function getPrefixes($str) {
		$prefixes = array();
		for ($i = 0; $i < strlen($str); $i++) {
			$prefixes[] = substr($str, 0, $i + 1);
		}
		return $prefixes;
	}
}
