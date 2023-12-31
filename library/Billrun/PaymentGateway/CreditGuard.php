<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents a payment gateway
 *
 * @since    5.2
 */
class Billrun_PaymentGateway_CreditGuard extends Billrun_PaymentGateway {

	protected $conf;
	protected $billrunName = "CreditGuard";
	protected $pendingCodes = "/$^/";
	protected $completionCodes = "/^000$/";
	protected $account;
        
	protected function __construct() {
		parent::__construct();
		$this->EndpointUrl = $this->getGatewayCredentials()['endpoint_url'];
	}

	public function updateSessionTransactionId() {
		$url_array = parse_url($this->redirectUrl);
		$str_response = array();
		parse_str($url_array['query'], $str_response);
		$this->transactionId = $str_response['txId'];
	}

	protected function buildPostArray($aid, $returnUrl, $okPage, $failPage) {
		$credentials = $this->getGatewayCredentials();
		$xmlParams['version'] = $credentials['version'] ?? '2000';
		$xmlParams['mpiValidation'] = 'Verify';
		$xmlParams['transactionType'] = 'RecurringDebit';
		$xmlParams['userData2'] = '';
		$xmlParams['aid'] = $aid;
		$xmlParams['ok_page'] = $okPage;
		$xmlParams['return_url'] = $returnUrl;
		$xmlParams['amount'] = (int) Billrun_Factory::config()->getConfigValue('CG.conf.amount', 100);
		$account = Billrun_Factory::account();
		$account->loadAccountForQuery(array('aid' => (int)$aid));
		$xmlParams['language'] = isset($account->pay_page_lang) ? $account->pay_page_lang : "ENG";
		$xmlParams['addFailPage'] = $failPage ? '<errorUrl>' . $failPage  . '</errorUrl>' : '';

		$customParams = $this->getGatewayCustomParams();


		return $this->getXmlStructureByParams($credentials, $xmlParams, ( !empty($customParams) ? $customParams : [])) ;
	}

	protected function updateRedirectUrl($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);

			if (isset($xmlObj->response->doDeal->mpiHostedPageUrl)) {

				$this->redirectUrl = (string)$xmlObj->response->doDeal->mpiHostedPageUrl;
				$this->setRequestParams();
			} else {
				Billrun_Factory::log("Error: " . 'Error Code: ' . $xmlObj->response->result .
					'Message: ' . $xmlObj->response->message .
					'Addition Info: ' . $xmlObj->response->additionalInfo, Zend_Log::ALERT);
				throw new Exception('Can\'t Create Transaction');
			}
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}
	
	protected function setRequestParams($params = []) {
		$this->requestParams = [
			'url' => $this->redirectUrl,
			'response_parameters' => [
				'txId',
			],
		];
	}

	protected function buildTransactionPost($txId, $additionalParams) {
		$params = $this->getGatewayCredentials();
		$params['txId'] = $txId;
		$params['tid'] = $params['redirect_terminal'];

		return $this->buildInquireQuery($params);
	}

	public function getTransactionIdName() {
		return "txId";
	}

	protected function getResponseDetails($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);
			// Example to print out status text
			if (!isset($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->result))
				return false;

			$this->saveDetails['card_token'] = (string) $xmlObj->response->inquireTransactions->row->cardId;
			$this->saveDetails['card_expiration'] = (string) $xmlObj->response->inquireTransactions->row->cardExpiration;
			$this->saveDetails['aid'] = (int) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->customerData->userData1;
			$this->saveDetails['personal_id'] = (string) $xmlObj->response->inquireTransactions->row->personalId;
			$this->saveDetails['auth_number'] = (string) $xmlObj->response->inquireTransactions->row->authNumber;
			$cardNum = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardNo;
			$retParams['action'] = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->customerData->userData2;
			$retParams['transferred_amount'] = $this->convertReceivedAmount(floatval($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->total));
			$retParams['transaction_status'] = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->status;
			$retParams['card_token'] = $this->saveDetails['card_token'];		
			$retParams['personal_id'] = $this->saveDetails['personal_id'];
			$retParams['auth_number'] = $this->saveDetails['auth_number'];
			$fourDigits = substr($cardNum, -4);
			$retParams['four_digits'] = $this->saveDetails['four_digits'] = $fourDigits;
			$retParams['expiration_date'] = (string) $xmlObj->response->inquireTransactions->row->cardExpiration;
			if ($retParams['action'] == 'SinglePayment') {
				$this->transactionId = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->tranId;
				$slaveNumber = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->slaveTerminalNumber;
				$slaveSequence = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->slaveTerminalSequence;
				$voucherNumber = $slaveNumber . $slaveSequence;
				$retParams['payment_identifier'] = $voucherNumber;
				$creditType = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->creditType;
				if (!empty((string) $xmlObj->response->inquireTransactions->row->xRem)) {
					$retParams['txid'] = (string) $xmlObj->response->inquireTransactions->row->xRem;
				}
				if ($creditType == 'Payments') {
					$retParams['installments'] = array();
					$retParams['installments']['total_amount'] = $this->convertReceivedAmount(floatval($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->total));
					$retParams['installments']['number_of_payments'] = (int)($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->numberOfPayments) + 1;
					$retParams['installments']['first_payment'] = $this->convertReceivedAmount(floatval($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->firstPayment));
					$retParams['installments']['periodical_payment'] = $this->convertReceivedAmount(floatval($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->periodicalPayment));
				}
			}

			return $retParams;
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}

	protected function buildSetQuery() {
		return array(
			'active' => array(
				'name' => $this->billrunName,
				'card_token' => (string) $this->saveDetails['card_token'],
				'card_expiration' => (string) $this->saveDetails['card_expiration'],
				'personal_id' => (string) $this->saveDetails['personal_id'],
				'transaction_exhausted' => true,
				'generate_token_time' => new Mongodloid_Date(time()),
				'auth_number' => (string) $this->saveDetails['auth_number'],
				'four_digits' => (string) $this->saveDetails['four_digits'],
			)
		);
	}

	public function getDefaultParameters() {
		$params = array("user", "password", "redirect_terminal", "charging_terminal", "mid", "endpoint_url", "version");
		return $this->rearrangeParametres($params);
	}
	
	public function getReceiverParameters() {
		$params = array("host", "user", "password", "remote_directory");
		return $this->rearrangeParametres($params);
	}

	public function getExportParameters() {
		$params = array("server", "user", "pw", "dir");
		return $this->rearrangeParametres($params);
	}

	public function authenticateCredentials($params) {
		$params['txId'] = 1;
		$authArray = $this->buildInquireQuery($params);
		$authString = http_build_query($authArray);
		if (function_exists("curl_init")) {
			Billrun_Factory::log("Sending to Credit Guard (authenticateCredentials): " . $params['endpoint_url'] . ' ' . $authString, Zend_Log::DEBUG);
			$result = Billrun_Util::sendRequest($params['endpoint_url'], $authString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		$codeResult = (string) $xmlObj->response->result;
		Billrun_Factory::log("Credit Guard response (authenticateCredentials):" . print_r($xmlObj, 1), Zend_Log::DEBUG);
		if ($codeResult == "405" || empty($result)) {
			Billrun_Factory::log("Credit Guard error (authenticateCredentials):" . print_r($xmlObj, 1), Zend_Log::ERR);
			return false;
		} else {
			return true;
		}
	}

	public function pay($gatewayDetails, $addonData) {
		$paymentArray = $this->buildPaymentRequset($gatewayDetails, 'RecurringDebit', $addonData);
		return $this->sendPaymentRequest($paymentArray);
	}

	protected function buildPaymentRequset($gatewayDetails, $transactionType, $addonData) {
		$credentials = $this->getGatewayCredentials();
		$customParams = $this->getGatewayCustomParams();
		$gatewayDetails['amount'] = $this->convertAmountToSend($gatewayDetails['amount']);
		$ZParameter = '';
		if (!empty($customParams['send_z_param'])) {
			$aidStringVal = strval($addonData['aid']);
			$addonData['aid'] = $this->addLeadingZero($aidStringVal);
			if (strlen($aidStringVal) > 8) { // Sent tag addonData(Z parameter) to CG must be 2-8 digits
				Billrun_Factory::log("Z parameter " . $addonData['aid'] . " sent to Credit Guard is larger than 8 digits", Zend_Log::NOTICE);
			}
			$ZParameter = !empty($addonData['aid']) ? '<addonData>' . $addonData['aid']  . '</addonData>' : '';
		}
		$this->transactionId = $addonData['txid'];
                $version = $credentials['version'] ?? '2000';
		return $post_array = array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
								<request>
								<command>doDeal</command>
								<requestId>23468</requestId>
								<version>' . $version . '</version>
								<language>Eng</language>
								<mayBeDuplicate>0</mayBeDuplicate>
									<doDeal>
										<terminalNumber>' . $credentials['charging_terminal'] . '</terminalNumber>
										<cardId>' . $gatewayDetails['card_token'] . '</cardId>
										<cardExpiration>' . $gatewayDetails['card_expiration'] . '</cardExpiration>
										<creditType>RegularCredit</creditType>
										<currency>' . $gatewayDetails['currency'] . '</currency>
										<transactionCode>Phone</transactionCode>
										<transactionType>' . $transactionType . '</transactionType>
										<total>' . abs($gatewayDetails['amount']) . '</total>
										' . ((!empty($gatewayDetails['auth_number']) && $gatewayDetails['amount'] > 0) ? '<authNumber>' . $gatewayDetails['auth_number'] . '</authNumber>' : '') . '
										<user>' . $this->transactionId . '</user>
										 ' . $ZParameter . '
										<validation>AutoComm</validation>
										 <customerData>
											<userData1>' . $addonData['aid'] . '</userData1>
	                                     </customerData>
									</doDeal>
								</request>
						</ashrait>'
		);
	}

	public function verifyPending($txId) {
		
	}

	public function hasPendingStatus() {
		return false;
	}
	
	protected function buildInquireQuery($params){
                $version = $params['version'] ?? '2000';
		return array(
			'user' => $params['user'],
			'password' => $params['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
							<request>
							 <language>HEB</language>
                                                         <version>' . $version . '</version>
							 <command>inquireTransactions</command>
							 <inquireTransactions>
							  <terminalNumber>' . $params['redirect_terminal'] . '</terminalNumber>
							  <mainTerminalNumber/>
							  <queryName>mpiTransaction</queryName>
							  <mid>' . (int)$params['mid'] . '</mid>
							  <mpiTransactionId>' . $params['txId'] . '</mpiTransactionId>
							  <mpiValidation>Token</mpiValidation>
							  <userData1/>
							  <userData2/>
							  <userData3/>
							  <userData4/>
							  <userData5/>
							 </inquireTransactions>
							</request>
					   </ashrait>'
		);
	}
	
	protected function isRejected($status) {
		return (!$this->isCompleted($status) && !$this->isPending($status));
	}
	
	protected function convertAmountToSend($amount) {
		$amount = round($amount, 2);
		return $amount * 100;
	}
	
	protected function convertReceivedAmount($amount) {
		return $amount / 100;
	}

	protected function isNeedAdjustingRequest(){
		return true;
	}
	
	protected function isUrlRedirect() {
		return true;
	}
	
	protected function isHtmlRedirect() {
		return false;
	}
		
	protected function needRequestForToken() {
		return true;
	}
	
	public function handleOkPageData($txId) {
		return true;
	}
	
	protected function validateStructureForCharge($structure) {
		return !empty($structure['card_token']) && !empty($structure['card_expiration']);
	}
	
	protected function handleTokenRequestError($response, $params) {
		return false;
	}

	protected function credit($gatewayDetails, $addonData) {
		$paymentArray = $this->buildPaymentRequset($gatewayDetails, 'Credit', $addonData);
		return $this->sendPaymentRequest($paymentArray);
	}
	
	protected function sendPaymentRequest($paymentArray) {
		$additionalParams = array();
		$codeResult = '';
		$paymentString = http_build_query($paymentArray);
		if (function_exists("curl_init")) {
			Billrun_Factory::log("Creditguard payment request: " . print_R($paymentArray, 1), Zend_Log::DEBUG);
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $paymentString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
			Billrun_Factory::log("Creditguard payment response: " . print_R($result, 1), Zend_Log::DEBUG);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		if ($xmlObj !== false) {
			$codeResult = (string) $xmlObj->response->result;
			$this->transactionId = (string) $xmlObj->response->tranId;
			$slaveNumber = (string) $xmlObj->response->doDeal->slaveTerminalNumber;
			$slaveSequence = (string) $xmlObj->response->doDeal->slaveTerminalSequence;
			$voucherNumber = $slaveNumber . $slaveSequence;
			if (!empty($voucherNumber)) {
				$additionalParams['payment_identifier'] = $voucherNumber;
			}
			$additionalParams['card_acquirer'] = $xmlObj->response->doDeal->cardAcquirer ? current($xmlObj->response->doDeal->cardAcquirer->attributes()->code) : '';
			$additionalParams['card_brand'] = $xmlObj->response->doDeal->cardBrand ? current($xmlObj->response->doDeal->cardBrand->attributes()->code) : '';
			$additionalParams['credit_company'] = $xmlObj->response->doDeal->creditCompany ? current($xmlObj->response->doDeal->creditCompany->attributes()->code) : '';
		}	
		return array('status' => $codeResult, 'additional_params' => $additionalParams);
	}
	
	public function handleTransactionRejectionCases($responseFromGateway, $paymentParams) {
		if ($responseFromGateway['stage'] != 'Rejected') {
			return false;
		}
		$cgConfig = Billrun_Factory::config()->getConfigValue('creditguard');
		$gatewayDetails = $paymentParams['gateway_details'];
		$updatedPaymentParams = $paymentParams;
		if ($responseFromGateway['status'] == $cgConfig['card_expiration_rejection_code'] && $this->isCreditCardExpired($gatewayDetails['card_expiration'])) {
			$updatedPaymentParams['gateway_details']['card_expiration'] = $gatewayDetails['card_expiration'] = $this->getCardExpiration($gatewayDetails['card_expiration']);
			if(!$this->updateAccountCardExpiration($paymentParams, $gatewayDetails)){
					return false;
			}
			return $updatedPaymentParams;
		}
		
		return false;
	}
	
	public function isCreditCardExpired($expiration) {
		$cgConfig = Billrun_Factory::config()->getConfigValue('creditguard');
		$oldestCardExpiration = $cgConfig['oldest_card_expiration'];
		$expires = \DateTime::createFromFormat('my', $expiration);
		$dateTooOld = new DateTime($oldestCardExpiration);
		if ($expires < $dateTooOld) {
			Billrun_Factory::log("Expiration date " . $expires->date . " is too old", Zend_Log::DEBUG);
			return false;
		}
		
		return $expires < new DateTime();
	}

	protected function buildSinglePaymentArray($params, $options) {
		$credentials = $this->getGatewayCredentials();
		$customParams = $this->getGatewayCustomParams();
		$addonData = array();
		$xmlParams['aid'] = $addonData['aid'] = $params['aid'];
		$xmlParams['version'] = $credentials['version'] ?? '2000';
		$xmlParams['mpiValidation'] = 'AutoComm';
		$xmlParams['userData2'] = 'SinglePayment';
		if (!empty($customParams['send_z_param'])) {
			$aidStringVal = strval($addonData['aid']);
			$addonData['aid'] = $this->addLeadingZero($aidStringVal);
			if (strlen($aidStringVal) > 8) { // Sent tag addonData(Z parameter) to CG must be 2-8 digits
				Billrun_Factory::log("Z parameter " . $addonData['aid'] . " sent to Credit Guard is larger than 8 digits", Zend_Log::NOTICE);
			}
		} else {
			unset($addonData['aid']);
		}
		$addonData['txid'] = $params['txid'];
		$xmlParams['ok_page'] = $params['ok_page'];
		$xmlParams['return_url'] = $params['return_url'];
		$xmlParams['amount'] = $this->convertAmountToSend($params['amount']);
		$query = array('aid' => (int) $params['aid']);
		$account = $this->account->loadAccountForQuery($query);
		$xmlParams['language'] = isset($account['pay_page_lang']) ? $account['pay_page_lang'] : "ENG";
		$xmlParams['addFailPage'] = $params['fail_page'] ? '<errorUrl>' . $params['fail_page']  . '</errorUrl>' : '';
		if (isset($options['installments'])) {
			$installmentParams['amount'] = $this->convertAmountToSend($options['installments']['total_amount']);
			$installmentParams['number_of_payments'] = $options['installments']['number_of_payments'] - 1;
			$installmentParams['periodical_payments'] = floor($installmentParams['amount'] / $options['installments']['number_of_payments']); 	
			$installmentParams['first_payment'] = $installmentParams['amount'] - ($installmentParams['number_of_payments'] * $installmentParams['periodical_payments']);
			return $this->getInstallmentXmlStructure($credentials, $xmlParams, $installmentParams, $addonData);
		}

		//add spesific  configration that to  be applies on each new payment page
		if(!empty($customParams) && is_array($customParams)) {
			$addonData = array_merge($customParams,$addonData);
		}

		return $this->getXmlStructureByParams($credentials, $xmlParams, $addonData);
	}
	
	protected function getXmlStructureByParams($credentials, $xmlParams, $addonData = array()) {
		$XParameter = !empty($addonData['txid']) ? '<user>' . $addonData['txid']  . '</user>' : '';
		$ZParameter = !empty($addonData['aid']) ? '<addonData>' . $addonData['aid']  . '</addonData>' : '';
		$ashraitEmvData = '<ashraitEmvData>
						<recurringTotalNo>999</recurringTotalNo>
						<recurringTotalSum></recurringTotalSum>
						<recurringFrequency>04</recurringFrequency>
					</ashraitEmvData>';

		return array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>                                      
							<request>
								 <version>' . $xmlParams['version'] . '</version>
								 <language>' . $xmlParams['language'] . '</language>
								 <dateTime/>
								 <command>doDeal</command>
								 <doDeal>
										  <successUrl>' . $xmlParams['ok_page'] . '</successUrl>
										  '. $xmlParams['addFailPage']  .'
										  <terminalNumber>' . $credentials['redirect_terminal'] . '</terminalNumber>
										 ' . $XParameter . '
										 ' . $ZParameter . '
										  <mainTerminalNumber/>
										  <cardNo>CGMPI</cardNo>
										  <total>' . $xmlParams['amount'] . '</total>
										  <transactionType>' . ($xmlParams['transactionType'] ? $xmlParams['transactionType'] : 'Debit') . '</transactionType>
										  <creditType>RegularCredit</creditType>
										  <currency>ILS</currency>
										  <transactionCode>Phone</transactionCode>
										  <authNumber/>
										  <numberOfPayments/>
										  <firstPayment/>
										  <periodicalPayment/>
										  <validation>TxnSetup</validation>
										  <dealerNumber/>
										  <mid>' . (int) $credentials['mid'] . '</mid>
										  <uniqueid>' . time() . rand(100, 1000) . '</uniqueid>
										  <mpiValidation>' . $xmlParams['mpiValidation'] . '</mpiValidation>' .
											($xmlParams['transactionType'] == 'RecurringDebit' ?  $ashraitEmvData : '' ) . '
										  <customerData>
										   <userData1>' . $xmlParams['aid'] . '</userData1>
										   <userData2>' . $xmlParams['userData2'] . '</userData2>
										   <userData3/>
										   <userData4/>
										   <userData5/>
										   <userData6/>
										   <userData7/>
										   <userData8/>
										   <userData9/>
										   <userData10/>
										  </customerData>
										  '. (!empty($addonData['paymentPageData']) ?
										  '<paymentPageData>
											'.(!empty($addonData['paymentPageData']['ppsJSONConfig']) &&
												null != json_encode($addonData['paymentPageData']['ppsJSONConfig']) ?
											'<ppsJSONConfig>
												'. json_encode($addonData['paymentPageData']['ppsJSONConfig'],JSON_PRETTY_PRINT| JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'
											</ppsJSONConfig>' : '') . '
										  </paymentPageData>
										  ' : '')
										  .'
								 </doDeal>
							</request>
						   </ashrait>'
		);
	}
	
	protected function getInstallmentXmlStructure($credentials, $xmlParams, $installmentParams, $addonData) {
		$ZParameter = !empty($addonData['aid']) ? '<addonData>' . $addonData['aid']  . '</addonData>' : '';
		return array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>                                      
							<request>
								 <version>' . $xmlParams['version'] . '</version>
								 <language>' . $xmlParams['language'] . '</language>
								 <dateTime/>
								 <command>doDeal</command>
								 <doDeal>
										  <successUrl>' . $xmlParams['ok_page'] . '</successUrl>
										  ' . $xmlParams['addFailPage'] . '
										  <terminalNumber>' . $credentials['redirect_terminal'] . '</terminalNumber>
										  <mainTerminalNumber/>
										  <cardNo>CGMPI</cardNo>
										  <total>' . $installmentParams['amount'] . '</total>
										  <user>' . $addonData['txid'] . '</user>
									      ' . $ZParameter . '
										  <transactionType>Debit</transactionType>
										  <creditType>Payments</creditType>
										  <currency>ILS</currency>
										  <transactionCode>Phone</transactionCode>
										  <authNumber/>
										  <numberOfPayments>' . $installmentParams['number_of_payments'] . '</numberOfPayments>
										  <firstPayment>' . $installmentParams['first_payment'] . '</firstPayment>
										  <periodicalPayment>' . $installmentParams['periodical_payments'] . '</periodicalPayment>
										  <validation>TxnSetup</validation>
										  <dealerNumber/>
										  <mid>' . (int) $credentials['mid'] . '</mid>
										  <uniqueid>' . time() . rand(100, 1000) . '</uniqueid>
										  <mpiValidation>' . $xmlParams['mpiValidation'] . '</mpiValidation>
										  <customerData>
										   <userData1>' . $xmlParams['aid'] . '</userData1>
										   <userData2>' . $xmlParams['userData2'] . '</userData2>
										   <userData3/>
										   <userData4/>
										   <userData5/>
										   <userData6/>
										   <userData7/>
										   <userData8/>
										   <userData9/>
										   <userData10/>
										  </customerData>
								 </doDeal>
							</request>
						   </ashrait>'
		);
	}
	
	protected function addLeadingZero($param) {
		return str_pad($param, 2, "0", STR_PAD_LEFT);
	}

	public function createRecurringBillingProfile($aid, $gatewayDetails, $params = []) {
		return false;
	}
	
	protected function getCardExpiration($old_card_expiration){
		$cgConfig = Billrun_Factory::config()->getConfigValue('creditguard');
		$years = $cgConfig['years_to_extend_card_expiration'];
		return substr($old_card_expiration, 0, 2) . ((substr($old_card_expiration, 2, 4) + $years) % 100);
	}
	
	protected function updateAccountCardExpiration($paymentParams, $gatewayDetails){
		$this->account->loadAccountForQuery(array('aid' => $paymentParams['aid']));
		$accountGateway = $this->account->payment_gateway;
		$accountGateway['active']['card_expiration'] = $gatewayDetails['card_expiration'];
		if (isset($accountGateway['active']['generate_token_time']->sec)) {
			$accountGateway['active']['generate_token_time'] = date("Y-m-d H:i:s", $accountGateway['active']['generate_token_time']->sec);
		}
		$time = date(Billrun_Base::base_datetimeformat);
		$query = array(
			'aid' => $paymentParams['aid'],
			'type' => 'account',
			'effective_date' => $time,
		);
		$update = array(
			'from' => $time,
			'payment_gateway' => $accountGateway,
		);
		Billrun_Factory::log("Updating expiration date for aid=" . $paymentParams['aid'] . " to date " . $gatewayDetails['card_expiration'], Zend_Log::DEBUG);
		try {
			$this->account->permanentChange($query, $update);
			Billrun_Factory::log("Expiration date was updated for aid=" . $paymentParams['aid'] . " to " . $gatewayDetails['card_expiration'], Zend_Log::DEBUG);
		} catch (Exception $ex) {
			Billrun_Factory::log("Expiration date " . $gatewayDetails['card_expiration'] . " was failed to update for aid=" . $paymentParams['aid'], Zend_Log::ALERT);
			return false;
		}
		return true;
	}
	
	public function extendCardExpiration($paymentParams, $gatewayDetails){
		$old_card_expiration = $gatewayDetails['card_expiration'];
		$gatewayDetails['card_expiration'] = $this->getCardExpiration($old_card_expiration);
		if ($this->updateAccountCardExpiration($paymentParams, $gatewayDetails)){
			return $gatewayDetails['card_expiration'];
		}
		return $old_card_expiration;
	}
	
	public function getSecretFields() {
		return array('password');
	}

	public function sendRecurringMigrationRequest($aid, $gatewayDetails){
		$credentials = $this->getGatewayCredentials();
		$xmlParams['version'] = $credentials['version'] ?? '2000';
		$postArray = $this->getRecurringMigrationXml($credentials, $xmlParams, $gatewayDetails);
		$postString = http_build_query($postArray);
		if (function_exists("curl_init")) {
			Billrun_Factory::log("Requesting token from " . $this->billrunName . " for account " . $aid, Zend_Log::INFO);
			Billrun_Factory::log("Payment gateway token request: " . print_R($postArray, 1), Zend_Log::DEBUG);
			$response = Billrun_Util::sendRequest($this->EndpointUrl, $postString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
			Billrun_Factory::log("Payment gateway token response: " . print_R($response, 1), Zend_Log::DEBUG);
		}
		return $response;
	}


	protected function getRecurringMigrationXml($credentials, $xmlParams, $gatewayDetails) {
		return array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>                                      
							<request>
								 <version>' . ($xmlParams['version'] ?? '2000') . '</version>
								 <dateTime>' . ($xmlParams['date'] ?? date('Y-m-d H:i:s')) . '</dateTime>
								 <command>doDeal</command>
								 <requestId></requestId>
								 <doDeal>
										  <terminalNumber>' . $credentials['redirect_terminal'] . '</terminalNumber>
										  <validation>verify</validation>
										  <total>100</total>
										  <groupId></groupId>
										  <currency>ILS</currency>
										  <creditType>RegularCredit</creditType>
										  <transactionCode>Phone</transactionCode>
										  <transactionType>RecurringMigration</transactionType>
										  <user></user>
										  <externalId></externalId>
										  <cardExpiration>' . $gatewayDetails['card_expiration'] . '</cardExpiration>
										  <cardNo></cardNo>
										  <cgUid></cgUid>
										  <cardId>' . $gatewayDetails['card_token'] . '</cardId>
										  <authNumber>' . $gatewayDetails['auth_number'] . '</authNumber>
										  <ashraitEmvData>
										 		 <recurringTotalNo>999</recurringTotalNo>
										 		 <recurringTotalSum></recurringTotalSum>
										 		 <recurringFrequency>04</recurringFrequency>
										  </ashraitEmvData>
										  <updateGroupId></updateGroupId>
								 </doDeal>
							</request>
						 </ashrait>'
		);
	}
}
