<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator class that will unifiy several cdrs to s single cdr if possible.
 * The class is basic rate that can evaluate record rate by different factors
 * 
 * @package  calculator
 * 
 * @since    2.6
 *
 */
class Billrun_Calculator_Unify extends Billrun_Calculator {

	protected $unifiedLines = array();
	protected $unificationFields;
	protected $mergedUpdateFields;
	protected $archivedLines = array();
	protected $unifiedToRawLines = array();
	protected $dateSeperation = "Ymd";
	protected $acceptArchivedLines = false;
	protected $protectedConcurrentFiles = true;
	protected $archiveDb;
//	protected $activeBillrun;
	protected $dbConcurrentPref = 'RP_PRIMARY';
	protected static $calcs = array();
	protected $writeConcern = 0;

	/**
	 * Create a new instance of the unify caclulator object.
	 * @param array $options - Array of input options to create the object by.
	 */
	public function __construct($options = array()) {
		parent::__construct($options);
		$this->init();
		if (isset($options['date_seperation'])) {
			$this->dateSeperation = $options['date_seperation'];
		}

		$this->unificationFields = $this->getUnificationFields($options);
		$this->mergedUpdateFields = $this->getMergedUpdateFields($this->unificationFields);

		if (isset($options['accept_archived_lines'])) {
			$this->acceptArchivedLines = $options['accept_archived_lines'];
		}

		if (isset($options['protect_concurrent_files'])) {
			$this->protectedConcurrentFiles = $options['protect_concurrent_files'];
		}

		// archive connection setting
		$this->archiveDb = Billrun_Factory::db();
	}

	protected function getMergedUpdateFields($unificationFields) {
		$updateFields = array();
		foreach ($unificationFields as $type => $settings) {
			$updateFields[$type] = array();
			foreach ($settings['fields'] as $fieldSpecific) {
				$updateFields[$type] = array_merge_recursive($updateFields[$type], $fieldSpecific['update']);
			}
		}
		return $updateFields;
	}

	/**
	 * Get the unification fields.
	 * returns generic unification fields merged with specific unification fields for specific file type (input processor)
	 * @param array $options - Array of input options.
	 * @return array The unification fields.
	 */
	protected function getUnificationFields($options) {
		if (isset($options['unification_fields'])) {
			return $options['unification_fields'];
		}
		$type = $options['type'];
		$basicUnificationFields = Billrun_Factory::config()->getConfigValue('unify.unification_fields', array());
		$fileTypeUnificationFields = Billrun_Util::getIn(Billrun_Factory::config()->getFileTypeSettings($type, true), array('unify', 'unification_fields'), array());
		$unificationFields = array_merge_recursive($fileTypeUnificationFields, $basicUnificationFields);
		if (empty($unificationFields)) {
			Billrun_Factory::log('Cannot get unification fields. options: ' . print_r($options, 1));
			return array();
		}
		
		return array($type => $unificationFields);
	}

	/**
	 * Initialize the data used for lines unification.
	 * (call this when you want to start unify again after the lines were saved to the DB)
	 */
	public function init() {
		$this->archivedLines = array();
		$this->unifiedToRawLines = array();
		$this->unifiedLines = array();
//		$this->activeBillrun = Billrun_Billrun::getActiveBillrun();
	}

	protected function setUnifiedLineDefaults(&$line) {
		$line['urt'] = new MongoDate(strtotime(date('Ymd 12:00:00', $line['urt']->sec)));
	}

	/**
	 * add a sigle row/line to unified line if there is no unified line then create one.
	 * @param array $rawRow the single row to unify.
	 * @return boolean true this can't fail (other then some php errors)
	 */
	public function updateRow($rawRow) {
		$newRow = $rawRow instanceof Mongodloid_Entity ? $rawRow->getRawData() : $rawRow;
		// we aligned the urt to one main timestamp to avoid DST issues; effect only unified data
		$this->setUnifiedLineDefaults($newRow);
		$updatedRowStamp = $this->getLineUnifiedLineStamp($newRow);

		$rawRow['u_s'] = $updatedRowStamp;
		$this->archivedLines[$newRow['stamp']] = $rawRow->getRawData();
		$this->unifiedToRawLines[$updatedRowStamp]['remove'][] = $newRow['stamp'];

		if (($this->protectedConcurrentFiles && $this->isLinesLocked($updatedRowStamp, array($newRow['stamp']))) ||
			(!$this->acceptArchivedLines && $this->isLinesArchived(array($newRow['stamp'])))) {
			Billrun_Factory::log("Line {$newRow['stamp']} was already applied to unified line $updatedRowStamp", Zend_Log::NOTICE);
			return true;
		}
		$typeFields = $this->getLineSpecificUpdateFields($newRow);
		$updatedRow = $this->getUnifiedRowForSingleRow($updatedRowStamp, $newRow, $typeFields);
		foreach ($typeFields as $key => $fields) {
			foreach ($fields as $field) {
				$val = Billrun_Util::getIn($newRow, $field, null);
				if ($key == '$inc' && !is_null($val)) {
					$updatedVal  = Billrun_Util::getIn($updatedRow, $field, 0) + (($val && is_numeric($val)) ? $val : 0);
					Billrun_Util::setIn($updatedRow, $field, $updatedVal);
				} else if ($key == '$set' && !is_null($val)) {
					Billrun_Util::setIn($updatedRow, $field, $val);
				}
			}
		}
		$updatedRow['lcount'] += 1;
		$this->unifiedLines[$updatedRowStamp] = $updatedRow;
		$this->unifiedToRawLines[$updatedRowStamp]['update'][] = $newRow['stamp'];

		return true;
	}

	protected function getLineSpecificUpdateFields($line) {
		$fields = array();
		foreach ($this->unificationFields[$line['type']]['fields'] as $field) {
			if ($this->verifyMatchField($field['match'], $line)) {
				$fields = array_merge_recursive($fields, $this->getUpdateQueries($field['update']));
			}
		}
		return $fields;
	}
	
	/**
	 * convert operations from new configuration structure to old structure
	 * 
	 * @param array $updateConfig
	 * @return array
	 */
	protected function getUpdateQueries($updateConfig) {
		$ret = array();
		foreach ($updateConfig as $conf) {
			if (!isset($ret[$conf['operation']])) {
				$ret[$conf['operation']] = $conf['data'];
			} else {
				$ret[$conf['operation']] = array_merge($ret[$conf['operation']], $conf['data']);
			}
		}
		return $ret;
	}

	/**
	 * saved the single rows that were unified to the archive.
	 * @return array containing the rows that were failed when trying to save to the archive.
	 */
	public function saveLinesToArchive() {
		$failedArchived = array();
		$linesArchivedStamps = array();
		$archLinesColl = $this->archiveDb->archiveCollection();
		$localLines = Billrun_Factory::db()->linesCollection();

		$archivedLinesCount = count($this->archivedLines);
		if ($archivedLinesCount > 0) {
			$saveOptions = array('w' => $this->writeConcern);
			try {
				Billrun_Factory::log('Saving ' . $archivedLinesCount . ' source lines to archive.', Zend_Log::INFO);
				$archLinesColl->batchInsert($this->archivedLines, $saveOptions);
				$this->data = array_diff_key($this->data, $this->archivedLines);
				$linesArchivedStamps = array_keys($this->archivedLines);
			} catch (Exception $e) {
				Billrun_Factory::log("Failed to insert to archive. " . $e->getCode() . " : " . $e->getMessage(), Zend_Log::ALERT);
				// todo: dump lines into file
			}
			Billrun_Factory::log('Removing Lines from the lines collection....', Zend_Log::INFO);
			$localLines->remove(array('stamp' => array('$in' => $linesArchivedStamps)), $saveOptions);
		}
		return $failedArchived;
	}

	/**
	 * uptade/create the unified lines in the DB.
	 * @return the unified lines that were failed to be updaed/created in the DB.
	 */
	public function updateUnifiedLines() {
		Billrun_Factory::log('Updating ' . count($this->unifiedLines) . ' unified lines...', Zend_Log::INFO);
		$db = Billrun_Factory::db();
		$updateFailedLines = array();
		foreach ($this->unifiedLines as $key => $row) {
			$query = array('stamp' => $key, 'type' => $row['type'], 'tx' => array('$nin' => $this->unifiedToRawLines[$key]['update']));
			$base_update = array(
				'$setOnInsert' => array(
					'stamp' => $key,
					'source' => 'unify',
					'type' => $row['type'],
//					'billrun' => $this->activeBillrun,
			));
			$update = array_merge($base_update, $this->getlockLinesUpdate($this->unifiedToRawLines[$key]['update']));
			foreach ($this->mergedUpdateFields[$row['type']] as $operations) {
				$fkey = $operations['operation'];
				$fields = $operations['data'];
				foreach ($fields as $field) {
					$val = Billrun_Util::getIn($row, $field, null);
					if (!is_null($val)) {
						$update[$fkey][$field] = $val;
					}
				}
			}
			$update['$inc']['lcount'] = $row['lcount'];

			$linesCollection = $db->linesCollection();
			$ret = $linesCollection->update($query, $update, array('upsert' => true, 'w' => $this->writeConcern));
			$success = ($this->writeConcern == 0 && $ret) || (($this->writeConcern > 0 || $this->writeConcern = 'majority') && isset($ret['ok']) && $ret['ok'] && isset($ret['ok']) && $ret['ok'] == 1);
			if (!$success) {
				$updateFailedLines[$key] = array('unified' => $row, 'lines' => $this->unifiedToRawLines[$key]['update']);
				foreach ($this->unifiedToRawLines[$key]['update'] as $lstamp) {
					unset($this->archivedLines[$lstamp]);
				}
				Billrun_Factory::log("Updating unified line $key failed.", Zend_Log::ERR);
			}
		}
		return $updateFailedLines;
	}

	public function write() {
		// update db.lines don't update the queue if  a given line failed.
		foreach ($this->updateUnifiedLines() as $failedLine) {
			foreach ($failedLine['lines'] as $stamp) {
				unset($this->lines[$stamp]);
			}
		}
		//add lines to archive 
		$this->saveLinesToArchive();

		parent::write();
	}

	/**
	 * Get or create a unified row from a given single row
	 * @param string $updatedRowStamp the unified stamp that the returned row should have.
	 * @param array $newRow the single row.
	 * @return array containing  a new or existing unified row.
	 */
	protected function getUnifiedRowForSingleRow($updatedRowStamp, $newRow, $typeFields) {
		$type = $newRow['type'];
		if (isset($this->unifiedLines[$updatedRowStamp])) {
			$existingRow = $this->unifiedLines[$updatedRowStamp];
			foreach ($typeFields['$inc'] as $field) {
				$newVal = Billrun_Util::getIn($newRow, $field, null);
				$exisingVal = Billrun_Util::getIn($existingRow, $field, null);
				if (!is_null($newVal) && is_null($exisingVal)) {
					Billrun_Util::setIn($existingRow, $field, 0);
				}
			}
		} else {
			//Billrun_Factory::log(print_r($newRow,1),Zend_Log::ERR);
			$existingRow = array('lcount' => 0, 'type' => $type);
			foreach ($typeFields as $key => $fields) {
				foreach ($fields as $field) {
					$newVal = Billrun_Util::getIn($newRow, $field, null);
					if ($key == '$inc' && !is_null($newVal)) {
						Billrun_Util::setIn($existingRow, $field, 0);
					} else if (!is_null($newVal)) {
						Billrun_Util::setIn($existingRow, $field, $newVal);
					} else {
						Billrun_Factory::log("Missing Field $field for row {$newRow['stamp']} when trying to unify.", Zend_Log::DEBUG);
					}
				}
			}
		}

		return $existingRow;
	}

	/**
	 * Get the unified row stamp for a given single line.
	 * @param type $newRow the single line to extract the unified row stamp from.
	 * @return a string  with the unified row stamp.
	 */
	protected function getLineUnifiedLineStamp($newRow) {
		$typeData = $this->unificationFields[$newRow['type']];
		$serialize_array = array();
		foreach ($typeData['stamp']['value'] as $field) {
			$newVal = Billrun_Util::getIn($newRow, $field, null);
			if (!is_null($newVal)) {
				Billrun_Util::setIn($serialize_array, $field, $newVal);
			}
		}

		foreach ($typeData['stamp']['field'] as $field) {
			$serialize_array['exists'][$field] = isset($newRow[$field]) ? '1' : '0';
		}
		if (($dateSeparationValue = $this->getDateSeparation($newRow, $typeData)) !== FALSE) {
			$serialize_array['dateSeperation'] = $dateSeparationValue;
		}
		return Billrun_Util::generateArrayStamp($serialize_array);
	}

	protected function getDateSeparation($line, $typeData) {
		$dateSeperation = (isset($typeData['date_seperation']) ? $typeData['date_seperation'] : $this->dateSeperation);
		return date($dateSeperation, $line['urt']->sec);
	}

	public function isLineLegitimate($line) {
		$matched = $line['source'] != 'unify' && isset($this->unificationFields[$line['type']]);

		if ($matched) {
			$requirements = $this->unificationFields[$line['type']]['required'];
			$matched = $this->verifyMatchField($requirements['match'], $line) && (count(array_intersect(array_keys($line->getRawData()), $requirements['fields'])) == count($requirements['fields']));
			if (!$matched && isset($this->unificationFields[$line['type']]['archive_fallback']) && $this->verifyMatchField($this->unificationFields[$line['type']]['archive_fallback'], $line)) {
				$this->archivedLines[$line['stamp']] = $line->getRawData();
			}
		}
		return $matched;
	}

	protected function verifyMatchField($rules, $line) {
		$matched = true;
		foreach ($rules as $field => $regex) {
			// @todo: make it pluginable with chain of responsibility
			if ($field == 'classMethod') {
				$matched &= call_user_func_array(array($this, $regex), array($line));
			} elseif (!preg_match($regex, $line[$field])) {
				$matched &= false;
			}
		}
		return $matched;
	}

	public function isNsnLineLegitimate($line) {
		if ((isset($line['arate']) && $line['arate'] !== false) || (isset($line['usaget']) && $line['usaget'] == 'incoming_call' && isset($line['sid']))) {
			return false;
		}
		return true;
	}

	/**
	 * 
	 * @param type $unifiedStamp
	 * @param type $lineStamps
	 * @return type
	 */
	protected function isLinesLocked($unifiedStamp, $lineStamps) {
		$query = array('stamp' => $unifiedStamp, 'tx' => array('$in' => $lineStamps));
		return !Billrun_Factory::db()->linesCollection()->query($query)->cursor()->limit(1)->current()->isEmpty();
	}

	/**
	 * Check if certain lines already inserted to the archive.
	 * @param type $lineStamps an array containing the line stamps to check
	 * @return boolean true if the line all ready exist in the archive false otherwise.
	 */
	protected function isLinesArchived($lineStamps) {
		$lineQuery = array('stamp' => array('$in' => $lineStamps));
		return !$this->archiveDb->archiveCollection()->query($lineQuery)->cursor()->limit(1)->current()->isEmpty();
	}

	/**
	 * Get the update argument/query to lock lines for a unfied line in a the DB.
	 * @param type $lineStamps the stamps of the lines to lock.
	 * @return array update query to pass on to an update  action.
	 */
	protected function getlockLinesUpdate($lineStamps) {
		$txarr = array();
		foreach ($lineStamps as $value) {
			$txarr[$value] = true;
		}
		$update = array('$push' => array('tx' => array('$each' => $lineStamps)));
		return $update;
	}
	
	/**
	 * Release lock for given lines in a unified line in the DB.
	 * @param type $unifiedStamp the unified line stamp to release the single line on.
	 * @param type $lineStamps the stamp of the single lines to release from lock.
	 */
	protected function releaseLines($unifiedStamp, $lineStamps) {
		$query = array('stamp' => $unifiedStamp);

		$update = array('$pullAll' => array('tx' => $lineStamps));
		Billrun_Factory::db()->linesCollection()->update($query, $update);
	}

	/**
	 * 
	 */
	public function releaseAllLines() {
		Billrun_Factory::log('Removing locks from  ' . count($this->unifiedToRawLines) . ' unified lines...', Zend_Log::DEBUG);
		foreach ($this->unifiedToRawLines as $key => $value) {
			$this->releaseLines($key, $value['remove']);
		}
	}

	/**
	 * 
	 * @return type
	 */
	protected function getLines() {
		$types = array('ggsn', 'smpp', 'mmsc', 'smsc', 'nsn', 'tap3', 'credit');
		return $this->getQueuedLines(array('type' => array('$in' => $types)));
	}

	/**
	 * 
	 * @return string
	 */
	public function getCalculatorQueueType() {
		return 'unify';
	}

	public function removeFromQueue() {
		parent::removeFromQueue();
		$this->releaseAllLines();
	}

	/**
	 * Return the lines that need(ed) archive
	 * @param arra $param
	 */
	public function getArchiveLines() {
		return $this->archivedLines;
	}

	public static function getInstance() {
		$args = func_get_args();

		$stamp = md5(serialize($args));
		if (isset(self::$instance[$stamp])) {
			return self::$instance[$stamp];
		}

		$line = $args[0]['line'];
		unset($args[0]['line']);

		$type = $line['type'];
		if (!isset(self::$calcs[$type])) {
			// @TODO: use always the first condition for all types - it will load the config values by default
			$class = 'Billrun_Calculator_Unify';
			if (isset($line['realtime']) && $line['realtime']) {
				$configOptions = Billrun_Factory::config()->getConfigValue('Unify_' . ucfirst($type), array());
				$options = array_merge($args[0], $configOptions, array('type' => $line['type']));
				$class .= '_Realtime';
			}
			self::$calcs[$type] = new $class($options);
			self::$calcs[$type]->init();
		}
		return self::$calcs[$type];
	}
	
	public function prepareData($lines) {
		
	}

}
