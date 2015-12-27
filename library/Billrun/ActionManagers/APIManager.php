<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Helper class to manage the api action managers.
 *
 * @author Tom Feigin
 */
class Billrun_ActionManagers_APIManager extends Billrun_ActionManagers_Manager {
	
	/**
	 * Get the action name from the input.
	 */
	protected function getActionName() {
		$input = $this->options['input'];
		
		$methodInput = $input->get('method');
		if(empty($methodInput)) {
			Billrun_Factory::log("getAction received invalid input", Zend_Log::INFO);
			return null;
		}
		
		$apiName = $this->options['api_name'];
		
		// Make sure that the API name and the method input are lower case with capital first.
		return ucfirst(strtolower($apiName)) . '_' . ucfirst(strtolower($methodInput));
	}
	
	/**
	 * Validate the input options parameters.
	 * @return true if valid.
	 */
	protected function validate() {
		return parent::validate() && 
			   isset($this->options['input'])			 && 
			   isset($this->options['api_name']);
	}
	
	/**
	 * This function receives input and returns a subscriber action instance after
	 * it already parsed the input into itself.
	 * @return Billrun_ActionManagers_Action Subscriber action
	 */
	public function getAction() {
		$action = parent::getAction();
		if(!$action) {
			return null;
		}
		
		$input = $this->options['input'];
		
		/**
		 * Parse the input data.
		 */
		if(!$action->parse($input)) {
			Billrun_Factory::log("APIAction getAction Action failed to parse input! " . 
								 print_r($input, 1), Zend_Log::INFO);
			return $action->getError();
		}
		
		return $action;
	}
}
