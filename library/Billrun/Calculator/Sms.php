<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Sms
 *
 * @author eran
 */
class Billrun_Calculator_Sms extends Billrun_Calculator_Base_Rate {
	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'data';	

	/**
	 * method to get calculator lines
	 */
	protected function getLines() {
		$lines = Billrun_Factory::db()->linesCollection();
		
		return $lines->query()
			->in('type', array('smpp','smsc'))
			->notExists('customer_rate')->cursor()->limit($this->limit);

	}

	/**
	 * write the calculation into DB.
	 * @param $row the line CDR to update. 
	 */
	protected function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array('row' => $row));

		$current = $row->getRawData();
		$rate = $this->getLineRate($row);	
		if($rate !== FALSE) {			
			$added_values = array(
				'customer_rate' => $rate['_id'],
			);
			$newData = array_merge($current, $added_values);
			$row->setRawData($newData);
		}
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array('row' => $row));
	}

	/**
	 * identify if the row belong to calculator
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
	protected function getLineRate($row) {		
		$called_number = preg_replace('/^0+/', '', $row->get('called_number'));

		$rates = Billrun_Factory::db()->ratesCollection();
		//Billrun_Factory::log()->log("row : ".print_r($row ,1),  Zend_Log::DEBUG);

		$called_number_prefixes = $this->getPrefixes($called_number);
		//Billrun_Factory::log()->log("prefixes : ".print_r($called_number_prefixes ,1),  Zend_Log::DEBUG);
		$base_match = array(
			'$match' => array(
				'params.prefix' => array(
					'$in' => $called_number_prefixes,
				),
				'sms' => array('$exists' => true )
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
	
	protected function getPrefixes($str) {
		$prefixes = array();
		for ($i = 0; $i < strlen($str); $i++) {
			$prefixes[] = substr($str, 0, $i + 1);
		}
		return $prefixes;
	}
}
