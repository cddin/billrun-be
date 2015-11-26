<?php

/**
 * Response to StartCall request
 */
class Billrun_ActionManagers_Realtime_Responder_Call_StartCall extends Billrun_ActionManagers_Realtime_Responder_Call_Base {

	public function getResponsApiName() {
		return 'start_call';
	}

	/**
	 * Gets the real dialed number
	 * @todo implement (maybe should be calculated during billing proccess)
	 * 
	 * @return the connect to number
	 */
	protected function getConnectToNumber() {
		return $this->row['calling_number'];
	}
	
	/**
	 * Gets acknowledge for freeCall request
	 * 
	 * @return int
	 */
	protected function getFreeCallAck() {
		return (isset($this->row['FreeCall']) && $this->row['FreeCall'] ? 1 : 0);
	}

}