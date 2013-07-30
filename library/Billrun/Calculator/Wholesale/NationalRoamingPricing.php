<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator for  pricing  billing lines with customer price.
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Wholesale_NationalRoamingPricing extends Billrun_Calculator_Wholesale {
	const DEF_CALC_DB_FIELD = 'price_nr';
	
	protected $pricingField = self::DEF_CALC_DB_FIELD;
	
	protected $nrCarriers = array();
	
	public function __construct($options = array()) {
		parent::__construct($options);
		foreach(Billrun_Factory::db()->carriersCollection()->query(array('key'=>'NR')) as $nrCarir) {
			$nrCarir->collection(Billrun_Factory::db()->carriersCollection());
			$this->nrCarriers[] = $nrCarir->createRef();
		}
	}
	
	protected function getLines() {
		$lines = Billrun_Factory::db()->linesCollection();
		return $lines->query(array(
								'type'=> 'nsn',
								'$or' => array(
									array( 'record_type' => '12', 'carir' => array('$in'=>$this->nrCarriers)),
									array('record_type' => '11', 'carir_in' => array('$in'=>$this->nrCarriers)),
								)
							))
				->exists('customer_rate')->notExists($this->pricingField)->cursor()->limit($this->limit);
	}

	protected function updateRow($row) {
		
		
		//@TODO  change this  be be configurable.
		$pricingData = array();
		$row->collection(Billrun_Factory::db()->linesCollection());
		$zoneKey = $this->isLineIncoming($row) ?  'incoming' : $row['customer_rate']['key'];

		if (isset($row['usagev']) && $zoneKey ) {
			$rates =  $this->getCarrierRateForZoneAndType($row['carir'], $zoneKey, $row['usaget'] );
			$pricingData = $this->getLinePricingData($row['usagev'], $rates);
			$row->setRawData(array_merge($row->getRawData(), $pricingData));
		}	
	}
}

