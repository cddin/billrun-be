<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a prototype for a encoder.
 *
 */
abstract class Billrun_Encoder_Base {
	/**
	 * Encode to string
	 * 
	 * @param type $elem the element to encode
	 * @return string the encoded value
	 */
	public abstract function encode($elem);
}