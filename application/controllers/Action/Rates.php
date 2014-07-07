<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * Rates action class
 *
 * @package  Action
 * 
 * @since    2.6
 */
class RatesAction extends ApiAction {

	public function execute() {
		Billrun_Factory::log()->log("Execute rates api call", Zend_Log::INFO);
		$request = $this->getRequest();
		
		$query = $this->processQuery($request->get('query', array()));		
		$model = new RatesModel();
		$results = $model->getData($query, array('key', 'rates'));

		$this->getController()->setOutput(array(array(
				'status' => 1,
				'desc' => 'success',
				'details' => $results,
				'input' => $request->getRequest(),
		)));

	}
	/**
	 * Process the query and prepere it for usage by the Rates model
	 * @param type $query the query that was recevied from the http request.
	 * @return array containing the processed query.
	 */
	protected function processQuery($query) {
		$retQuery = array();
		if (isset($query)) {
			if (is_string($query)) {
				$retQuery = json_decode($query, true);
			} else {
				$retQuery = (array) $query;
			}
			
			if(!isset($retQuery['from'])) {
				$retQuery['from']['$lte'] = new MongoDate();
			}
			if(!isset($retQuery['to'])) {
				$retQuery['to']['$gte'] = new MongoDate();
			}
		}
		
		return $retQuery;
	}

}