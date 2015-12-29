<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing cron controller class
 * Used for is alive checks
 * 
 * @package  Controller
 * @since    2.8
 */
class CronController extends Yaf_Controller_Abstract {

	protected $mailer;
	protected $smser;

	public function init() {
		Billrun_Factory::log("BillRun Cron is running", Zend_Log::INFO);
		$this->smser = Billrun_Factory::smser();
		$this->mailer = Billrun_Factory::mailer();
	}

	/**
	 * main action to do basic tests
	 * 
	 * @return void
	 */
	public function indexAction() {
		// do nothing
	}

	public function receiveAction() {
		Billrun_Factory::log("Check receive", Zend_Log::INFO);
		$alerts = $this->locate(('receive'));
		if (!empty($alerts)) {
			$this->sendAlerts('receive', $alerts);
		}
	}

	public function processAction() {
		Billrun_Factory::log("Check process", Zend_Log::INFO);
		$alerts = $this->locate(('process'));
		if (!empty($alerts)) {
			$this->sendAlerts('process', $alerts);
		}
	}

	protected function locate($process) {
		$logsModel = new LogModel();
		$empty_types = array();
		$filter_field = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.field');
		$types = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.types', array());
		foreach ($types as $type => $timediff) {
			$query = array(
				'source' => $type,
				$filter_field => array('$gt' => date('Y-m-d H:i:s', (time() - $timediff)))
			);
			$results = $logsModel->getData($query)->current();
			if ($results->isEmpty()) {
				$empty_types[] = $type;
			}
		}
		return $empty_types;
	}

	protected function sendAlerts($process, $empty_types) {
		if (empty($empty_types)) {
			return ;
		}
		$events_string = implode(', ', $empty_types);
		Billrun_Factory::log("Send alerts for " . $process, Zend_Log::INFO);
		Billrun_Factory::log("Events types: " . $events_string, Zend_Log::INFO);
		$actions = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.actions', array());
		if (isset($actions['email'])) {
			//'GT BillRun - file did not %s: %s'
			if (isset($actions['email']['recipients'])) {
				$recipients = $actions['email']['recipients'];
			} else {
				$recipients = $this->getEmailsList();
			}
			$this->mailer->addTo($recipients);
			$this->mailer->setSubject($actions['email']['subject']);
			$message = sprintf($actions['email']['message'], $process, $events_string);
			$this->mailer->setBodyText($message);
			$this->mailer->send();
		}
		if (isset($actions['sms'])) {
			//'GT BillRun - file types did not %s: %s'
			$message = sprintf($actions['sms']['message'], $process, $events_string);
			if (isset($actions['sms']['recipients'])) {
				$recipients = $actions['sms']['recipients'];
			} else {
				$recipients = $this->getSmsList();
			}
			$this->smser->send($message, $recipients);
		}
	}

	public function autoRenewServices() {		
		$collection = Billrun_Factory::db()->subscribers_auto_renew_servicesCollection();
		
		$queryDate = array('creation_time' => strtotime('-1 month'));
		$queryDate['remain'] = array('$gt' => 0);
		
		// Check if last day.
		if(date('d') == date('t')) {
			$queryDate = array('$or' => $queryDate);
			$queryDate['$or']['$and']['eom'] = 1;
			$queryDate['$or']['$and']['creation_time']['$gt'] = strtotime('-1 month');
			$queryDate['$or']['$and']['creation_time']['$lt'] = strtotime('first day of this month');
		}
		
		$autoRenewCursor = $collection->query($queryDate)->cursor();
		
		// Go through the records.
		foreach ($autoRenewCursor as $autoRenewRecord) {
			$this->updateBalanceByAutoRenew($autoRenewRecord);
			
			$this->updateAutoRenewRecord($collection);
		}
	}
	
	/**
	 * Check if we are in 'dead' days
	 * @return boolean
	 */
	protected function areDeadDays() {
		$lastDayLastMonth = date('d', strtotime('last day of previous month'));
		$today = date('d');
		
		if($lastDayLastMonth <= $today) {
			$lastDay = date('t');
			if($today != $lastDay) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Update the auto renew record after usage.
	 * @param type $collection
	 * @return type
	 */
	protected function updateAutoRenewRecord($collection) {
		$autoRenewRecord['remain'] = $autoRenewRecord['remain'] - 1;
		
		if($autoRenewRecord['eom'] == 1) {
			$autoRenewRecord['last_renew_date'] = new MongoDate(strtotime('last day of this month'));
		} else {
			$autoRenewRecord['last_renew_date'] = new MongoDate();
		}
		$autoRenewRecord['done'] = $autoRenewRecord['done'] + 1;

		return $collection->updateEntity($autoRenewRecord);
	}
	
	protected function updateBalanceByAutoRenewAction($autoRenewRecord) {
		$this->updateBalanceByAutoRenew($autoRenewRecord);
	}
	
	/**
	 * Update a balance according to a auto renew record.
	 * @param type $autoRenewRecord
	 * @return boolean
	 */
	protected function updateBalanceByAutoRenew($autoRenewRecord) {
		$updater = new Billrun_ActionManagers_Balances_Update(); 

		$updaterInput['method'] = 'update';
		$updaterInput['sid'] = $autoRenewRecord['sid'];

		// Build the query
		$updaterInputQuery['charging_plan_external_id'] = $autoRenewRecord['charging_plan_external_id'];
		$updaterInputUpdate['from'] = $autoRenewRecord['from'];
		$updaterInputUpdate['to'] = $autoRenewRecord['to'];
		$updaterInputUpdate['operation'] = $autoRenewRecord['operation'];

		$updaterInput['query'] = $updaterInputQuery;
		$updaterInput['update'] = $updaterInputUpdate;

		$jsonFormat = json_encode($updaterInput,JSON_FORCE_OBJECT);
		if(!$updater->parse($jsonFormat)) {
			// TODO: What do I do here?
			return false;
		}
		if(!$updater->execute()) {
			// TODO: What do I do here?
			return false;
		}
		
		return true;
	}
	
	/**
	 * method to add output to the stream and log
	 * 
	 * @param string $content the content to add
	 */
	public function addOutput($content) {
		Billrun_Log::getInstance()->log($content, Zend_Log::INFO);
	}

	protected function getEmailsList() {
		return Billrun_Factory::config()->getConfigValue('cron.log.mail_recipients', array());
	}

	protected function getSmsList() {
		return Billrun_Factory::config()->getConfigValue('cron.log.sms_recipients', array());
	}

}
