<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2019 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Generator CSV for payment gateways files
 */
class Billrun_Generator_PaymentGateway_Csv {
	
	protected $data = array();
	protected $headers = array();
	protected $trailers = array();
	protected $delimiter;
	protected $fixedWidth = false;
	protected $padDirDef = STR_PAD_LEFT;
	protected $padCharDef = ' ';
	protected $filePath;

	public function __construct($options) {
		$this->fixedWidth = isset($options['type']) && ($options['type'] == 'fixed') ? true : false;
		$this->data = isset($options['data']) ? $options['data'] : $this->data;
		$this->headers = isset($options['headers']) ? $options['headers'] : $this->headers;
		$this->trailers = isset($options['trailers']) ? $options['trailers'] : $this->trailers;
		if (isset($options['delimiter'])) {
			$this->delimiter = $options['delimiter'];
		} else if ($this->fixedWidth) {
			$this->delimiter = '';
		}
		if (!$this->validateOptions($options)) {
			Billrun_Factory::log("Missing options when generating payment gateways csv file for file type " . $options['file_type'], Zend_Log::DEBUG);
			return false;
		}
		
		$this->filePath = $options['local_dir'] . DIRECTORY_SEPARATOR . $options['file_name'];
	}
	
	protected function validateOptions($options) {
		if (isset($options['type']) && !in_array($options['type'], array('fixed', 'separator'))) {
			return false;
		}
		if (!isset($options['file_name']) || !isset($options['local_dir'])) {
			return false;
		}
		if ($this->fixedWidth) {
			foreach ($this->data as $dataLine) {
				foreach ($dataLine as $dataObj) {
					if (!isset($dataObj['padding']['length'])) {
						Billrun_Factory::log("Missing padding length definitions for " . $options['file_type'], Zend_Log::DEBUG);
						return false;
					}
				}
			}
		}
		return true;
	}
	
	public function generate() {
		if (count($this->data)) {
			$this->writeHeaders();
			$this->writeRows();
			$this->writeTrailers();
		}
		return;
	}
	
	protected function writeToFile($str) {
		return file_put_contents($this->filePath, $str, FILE_APPEND);
	}

	protected function writeHeaders() {
		$fileContents = '';
		$counter = 0;
		foreach ($this->headers as $entity) {
			$counter++;
			if (!is_array($entity)) {
				$entity = $entity->getRawData();
			}
			$fileContents .= $this->getRowContent($entity);
			$fileContents .= PHP_EOL;
			if ($counter == 50000) {
				$this->writeToFile($fileContents);
				$fileContents = '';
				$counter = 0;
			}
		}
		$this->writeToFile($fileContents);
	}
	
	protected function writeTrailers() {
		$fileContents = '';
		$counter = 0;
		foreach ($this->trailers as $entity) {
			$counter++;
			if (!is_array($entity)) {
				$entity = $entity->getRawData();
			}
			$fileContents .= $this->getRowContent($entity);
			$fileContents .= PHP_EOL;
			if ($counter == 50000) {
				$this->writeToFile($fileContents);
				$fileContents = '';
				$counter = 0;
			}
		}
		$this->writeToFile($fileContents);
	}
		
	protected function writeRows() {
		$fileContents = '';
		$counter = 0;
		foreach ($this->data as $index => $entity) {
			$counter++;
			if (!is_array($entity)) {
				$entity = $entity->getRawData();
			}
			$fileContents .= $this->getRowContent($entity);
			if ($index < count($this->data) - 1){
				$fileContents.= PHP_EOL;
			}
			if ($counter == 50000) {
				$this->writeToFile($fileContents);
				$fileContents = '';
				$counter = 0;
			}
		}
		if (!empty($this->trailers)) {
			$fileContents.= PHP_EOL;
		}
		$this->writeToFile($fileContents);
	}
	
	protected function getRowContent($entity) {
		if (!$this->fixedWidth) {
                    
			return $this->getDelimetedLine($entity);
		}
		$rowContents = '';
		
		foreach ($entity as $entityObj) {
			$padDir = isset($entityObj['padding']['direction']) ? $this->getPadDirection($entityObj['padding']['direction']) : $this->padDirDef;
			$padChar = isset($entityObj['padding']['character']) ? $entityObj['padding']['character'] : $this->padCharDef;
			$length = isset($entityObj['padding']['length']) ? $entityObj['padding']['length'] : strlen($entityObj['value']);
			$rowContents.=str_pad((isset($entityObj['value']) ? substr($entityObj['value'], 0, $length) : ''), $length, $padChar, $padDir);
		}
		return $rowContents;
	}

	protected function getPadDirection($dirStr) {
		switch ($dirStr) {
			case 'left':
				return STR_PAD_LEFT;
			case 'right':
				return STR_PAD_RIGHT;
			default:
				return $this->padDirDef;
		}
	}
	
	protected function getDelimetedLine($rowEntityDef) {
		$rowValues = array_column($rowEntityDef, 'value');
		return implode($this->delimiter, $rowValues);
	}

}

