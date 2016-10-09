<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/library/vendor/autoload.php';

/**
 * This class represents a payment gateway
 *
 * @since    5.2
 */
abstract class Billrun_PaymentGateway {
	use Billrun_Traits_Api_PageRedirect;
	
	protected $omnipayName;
	protected $omnipayGateway;
	protected static $paymentGateways;
	protected $redirectUrl;
	//protected $failUrl;
	protected $EndpointUrl;
	protected $saveDetails;
	protected $billrunName;
	protected $transactionId;




	private function __construct() {
		
		if ($this->supportsOmnipay()) {
			$this->omnipayGateway = Omnipay\Omnipay::create($this->getOmnipayName());
		}
		
	}

	abstract function updateSessionTransactionId();

	public function __call($name, $arguments) {
		if ($this->supportsOmnipay()) {
			return call_user_func_array(array($this->omnipayGateway, $name), $arguments);
		}
		throw new Exception('Method ' . $name . ' is not supported');
	}

	/**
	 * 
	 * @param string $name the payment gateway name
	 * @return Billrun_PaymentGateway
	 */
	public static function getInstance($name) {
		if (isset(self::$paymentGateways[$name])) {
			$paymentGateway = self::$paymentGateways[$name];
		} else {
			$subClassName = __CLASS__ . '_' . $name;
			if (@class_exists($subClassName)) {
				$paymentGateway = new $subClassName();
				self::$paymentGateways[$name] = $paymentGateway;
			}
		}
		return isset($paymentGateway)? $paymentGateway : NULL;
	}

	public function supportsOmnipay() {
		return !is_null($this->omnipayName);
	}

	public function getOmnipayName() {
		return $this->omnipayName;
	}
	
	public function redirectForToken($aid, $returnUrl, $timestamp){
		$this->getToken($aid, $returnUrl);
		$this->updateSessionTransactionId();
		
		// Signal starting process.
		$this->signalStartingProcess($aid, $timestamp);
		$this->forceRedirect($this->redirectUrl);
	}
	
	public function isSupportedGateway($gateway){
		$supported = Billrun_Factory::config()->getConfigValue('PaymentGateways.supported');
		return in_array($gateway, $supported);
	}
	
	abstract protected function charge();
	
	abstract protected function updateRedirectUrl($result);
		
	abstract protected function buildPostArray($aid, $returnUrl, $okPage);
	
	abstract protected function buildTransactionPost($txId);
	
	abstract public function getTransactionIdName();
	
	abstract protected function getResponseDetails($result);
	
	abstract protected function buildSetQuery();
	



//	public function getToSuccessPage(){  // TODO: Meantime there's another function named getOkPage who does the same function.
//		$this->forceRedirect($this->successUrl);
//	}
	
//	public function getToFailurePage(){
//		$this->forceRedirect($this->failUrl);
//	}
//	
	
	protected function getToken($aid, $returnUrl){
		$okTemplate = Billrun_Factory::config()->getConfigValue('PaymentGateways.ok_page');
		$okPage = sprintf($okTemplate, $this->billrunName);
		$postArray = $this->buildPostArray($aid, $returnUrl, $okPage);
		$postString = http_build_query($postArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $postString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		$this->updateRedirectUrl($result);
	}
	
	
	public function saveTransactionDetails($txId){
		$postArray = $this->buildTransactionPost($txId);
		$postString = http_build_query($postArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $postString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}		
		if ($this->getResponseDetails($result) === FALSE){
			return $this->setError("Operation Failed. Try Again...");
		}
		if (empty($this->saveDetails['aid'])){
			$this->getAidFromProxy();
		}
		if(!$this->validatePaymentProcess($txId)) {
			return $this->setError("Operation Failed. Try Again...");			
		}
		
		$today = new MongoDate();
		$this->subscribers = Billrun_Factory::db()->subscribersCollection();
		$setQuery = $this->buildSetQuery();
		$this->subscribers->update(array('aid' => (int) $this->saveDetails['aid'], 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"), array('$set' => $setQuery));
		if (isset($this->saveDetails['return_url'])){
			$returnUrl = (string)$this->saveDetails['return_url'];
			$this->forceRedirect($returnUrl);
		}
		$successUrl = Billrun_Factory::config()->getConfigValue('PaymentGateways.success_url');  // TODO: check	if more correct to define it when we get the request(getRequestAction)
		$this->forceRedirect($successUrl);
	}
	
	protected function signalStartingProcess($aid, $timestamp) {
		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		$query = array("name" => $this->billrunName, "tx" => $this->transactionId, "aid" => $aid);
		$paymentRow = $paymentColl->query($query)->cursor()->current();
		if(!$paymentRow->isEmpty()) {
			if(isset($paymentRow['done'])) { 
			   return;
			}
			return;
		}
		
		// Signal start process
		$query['t'] = $timestamp;
		$paymentColl->insert($query);
	}
	
	
	/**
	 * Check that the process that has now ended, actually started, and not too long ago.
	 * @return boolean
	 */
	protected function validatePaymentProcess($txId) {
		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		
		// Get is started
		$query = array("name" => $this->billrunName, "tx" => $txId); // removing aid shoud solve the problem.
		$paymentRow = $paymentColl->query($query)->cursor()->current();
		if($paymentRow->isEmpty()) {
			// Received message for completed charge, 
			// but no indication for charge start
			return false;
		}
		
		// Check how long has passed.
		$timePassed = time() - $paymentRow['t'];
		
		// Three minutes
		// TODO: What value should we put here?
		// TODO: Change to 4 hours, move to conf
		if($timePassed > 60*60*4) {
			// Change indication in DB for failure.
			$paymentRow['done'] = false;
		} else {
			// Signal done
			$paymentRow['done'] = true;	
		}
		
		$paymentColl->updateEntity($paymentRow);
		
		return $paymentRow['done'];
	}
	
//	public function operationFailed(){
//		printr("try again");
//		
//	}
	
	
	
	
	// if omnipay supported need to use this function for making charge, for others like CG need to implement it.
	// TODO: need to check for omnipay supported gateways.
	public function makePayment(){
//		if (!$this->supportsOmnipay()){
//			
//		}
//    else {
        $omnipay  = Omnipay\Omnipay::create('PayPal_Express');
        $omnipay->setUsername("shani.dalal_api1.billrun.com");
        $omnipay->setPassword("RRM2W92HC9VTPV3Y");
        $omnipay->setSignature("AiPC9BjkCyDFQXbSkoZcgqH3hpacA3CKMEmo7jRUKaB3pfQ8x5mChgoR");
        $omnipay->setTestMode(true);
        $purchaseData   = [
                      'testMode'    => true,
                      'amount'      => 1.00,
                      'currency'    => 'USD',
                      'returnUrl'   => 'http://www.google.com',
                      'cancelUrl'   => 'http://www.ynet.co.il'
					  
        ];
        $response = $omnipay->purchase($purchaseData)->send();
        $ref = $response->getTransactionReference();
        if(!is_null($ref)) { // when there's a Token
          $response->redirect();
        }else{
           //dd("ERROR");   <= This line works but if I put a redirect method as shown below it just shows a blank page. No errors nothing!
           return redirect(route('payment.error'));
        }   
   // }


	}
	
}
