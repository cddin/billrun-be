<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2018 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing abstract exporter bulk (multiple rows at once) to a file
 *
 * @package  Billing
 * @since    5.9
 */
abstract class Billrun_Generator_File {
	
	/**
	 * configuration for internal use of the exporter
	 * 
	 * @var array
	 */
	protected $config = array();
	
	/**
	 * collection name (DB) from which data should be fetched
	 * @var string
	 */
	protected $collection = null;
	
	/**
	 * query by which data should be fetched from DB
	 * @var array
	 */
	protected $query = array();
	
	/**
	 * data to export (after translation)
	 * @var array
	 */
	protected $rowsToExport = array();
	
	/**
	 * header to export (after translation)
	 * @var type 
	 */
	protected $headerToExport = null;
	
	/**
	 * footer to export (after translation)
	 * @var type 
	 */
	protected $footerToExport = null;
	
	/**
	 * raw lines from DB that should be exported (before translation)
	 * @var type 
	 */
	protected $rawRows = array();
	
	public function __construct($options) {
		$this->config = $options;
	}


	protected function buildGeneratorOptions() {
		$this->fileNameParams = isset($this->config['filename_params']) ? $this->config['filename_params'] : '';
		$this->fileNameStructure = isset($this->config['filename']) ? $this->config['filename'] : '';
		$this->rowsToExport = $this->loadRows();
        $options['data'] = $this->rowsToExport;
		$this->headerToExport[0] = $this->getHeaderLine();
        $options['headers'] = $this->headerToExport;
		$this->footerToExport[0] = $this->getTrailerLine();
        $options['trailers'] = $this->footerToExport;
        $options['type'] = $this->config['generator']['type'];
        $options['configByType'] = $this->config;
        if (isset($this->config['generator']['separator'])) {
            $options['delimiter'] = $this->config['generator']['separator'];
        }
        $options['file_type'] = $this->config['file_type'];
		$localDir = $this->getFilePath();
		$options['local_dir'] = $localDir;
		$fileName = $this->getFilename();
		$options['file_name'] = $fileName;
		$options['file_path'] = $localDir . DIRECTORY_SEPARATOR . $fileName;
        return $options;
    }
	
	protected function getGeneratorClassName() {
        if (!isset($this->config['generator']['type'])) {
            $message = 'Missing generator type for ' . $this->config['file_type'];
            throw new Exception($message);
        }
        switch ($this->config['generator']['type']) {
            case 'fixed':
            case 'separator':
                $generatorType = 'Csv';
                break;
            case 'xml':
                $generatorType = 'Xml';
                break;
            default:
                $message = 'Unknown generator type for ' . $this->config['file_type'];
                throw new Exception($message);
        }

        $className = "Billrun_Generator_PaymentGateway_" . $generatorType;
        return $className;
    }
	
	protected function getHeaderLine() {
        $headerStructure = $this->config['generator']['header_structure'];
        return $this->buildLineFromStructure($headerStructure);
    }

    protected function getTrailerLine() {
        $trailerStructure = $this->config['generator']['trailer_structure'];
        return $this->buildLineFromStructure($trailerStructure);
    }
	
	    protected function getDataLine($params =  null) {
        $dataStructure = $this->config['generator']['data_structure'];
        return $this->buildLineFromStructure($dataStructure, $params);
    }
	
	protected function buildLineFromStructure($structure, $params = null) {
        $line = array();
        foreach ($structure as $field) {
            if (!isset($field['path'])) {
                $message = "Exporter " . $this->config['file_type'] . " header/trailer structure is missing a path";
                Billrun_Factory::log($message, Zend_Log::ERR);
                continue;
            }
            if (isset($field['hard_coded_value'])) {
                $line[$field['path']] = $field['hard_coded_value'];
            }
			if (isset($field['linked_entity'])) {
				$line[$field['path']] = $this->getLinkedEntityData($field['linked_entity']['entity'], $params, $field['linked_entity']['field_name']);
			}
            if (isset($field['type']) && $field['type'] !== 'string') {
                $line[$field['path']] = $this->getTranslationValue(array_merge($field, array('value' => 'now')));
            }
            if (!isset($line[$field['path']])) {
                $configObj = $field['name'];
                $message = "Field name " . $configObj . " config was defined incorrectly when generating file type " . $this->config['file_type'];
                throw new Exception($message);
            }
            
            $attributes = $this->getLineAttributes($field);
            
            if (isset($field['number_format'])) {
                $line[$field['path']] = $this->setNumberFormat($field, $line);
            }
            $line[$field['path']] = $this->prepareLineForGenerate($line[$field['path']], $field, $attributes);
        }
        if ($this->config['generator']['type'] == 'fixed' || $this->config['generator']['type'] == 'separator') {
            ksort($line);
        }
        return $line;
    }
	
	protected function setNumberFormat($field, $line) {
        if((!isset($field['number_format']['dec_point']) && (isset($field['number_format']['thousands_sep']))) || (isset($field['number_format']['dec_point']) && (!isset($field['number_format']['thousands_sep'])))){
            $message = "'dec_point' or 'thousands_sep' is missing in one of the entities, so only 'decimals' was used, when generating file type " . $this->config['file_type'];
            Billrun_Factory::log($message, Zend_Log::WARN);
        }
        if (isset($field['number_format']['dec_point']) && isset($field['number_format']['thousands_sep']) && isset($field['number_format']['decimals'])){
            return number_format((float)$line[$field['path']], $field['number_format']['decimals'], $field['number_format']['dec_point'], $field['number_format']['thousands_sep']);
        } else {
            if (isset($field['number_format']['decimals'])){
                return number_format((float)$line[$field['path']], $field['number_format']['decimals']); 
            }
        }
    }
	
	    /**
     * Function returns line's attributes, if exists
     * @param type $field
     * @return array $attributes.
     */
    protected function getLineAttributes($field){
        if(isset($field['attributes'])){
            return $field['attributes'];
        } else {
            return [];
        }
    }
	
	protected function prepareLineForGenerate($lineValue, $addedData, $attributes) {
        $newLine = array();
		$newLine['value'] = $lineValue;
        $newLine['name'] = $addedData['name'];
        if (count($attributes) > 0) {
            for ($i = 0; $i < count($attributes); $i++) {
                $newLine['attributes'][] = $attributes[$i];
            }
        }
        if (isset($addedData['padding'])) {
            $newLine['padding'] = $addedData['padding'];
        }
        return $newLine;
    }
	
	/**
	 * gets file path for export
	 * 
	 * @return string
	 */
	protected function getFilename() {
        if (!empty($this->fileName)) {
            return $this->fileName;
        }
        $translations = array();
        if(is_array($this->fileNameParams)){
            foreach ($this->fileNameParams as $paramObj) {
                $translations[$paramObj['param']] = $this->getTranslationValue($paramObj);
            }
        }
        $this->fileName = Billrun_Util::translateTemplateValue($this->fileNameStructure, $translations, null, true);
        return $this->fileName;
    }
	
	protected function getTranslationValue($paramObj) {
        if (!isset($paramObj['type']) || !isset($paramObj['value'])) {
            $message = "Missing filename params definitions for file type " . $this->config['file_type'];
            Billrun_Factory::log($message, Zend_Log::ERR);
        }
        switch ($paramObj['type']) {
            case 'date':
                $dateFormat = isset($paramObj['format']) ? $paramObj['format'] : Billrun_Base::base_datetimeformat;
                $dateValue = ($paramObj['value'] == 'now') ? time() : strtotime($paramObj['value']);
                return date($dateFormat, $dateValue);
            case 'autoinc':
                $minValue = $paramObj['min_value'] ?? 1;
                $maxValue = $paramObj['max_value'] ?? ($paramObj['padding']['length'] ? intval(str_repeat("9", $paramObj['padding']['length'])) : null);
				if (!isset($minValue) && !isset($maxValue)) {
                    $message = "Missing filename params definitions for file type " . $this->config['file_type'];
                    Billrun_Factory::log($message, Zend_Log::ERR);
                    return;
                }
                $dateGroup = isset($paramObj['date_group']) ? $paramObj['date_group'] : Billrun_Base::base_datetimeformat;
                $dateValue = ($paramObj['value'] == 'now') ? time() : strtotime($paramObj['value']);
                $date = date($dateGroup, $dateValue);
                $this->action = 'export_generator';//need to give by the constractor 
				$this->generateType = '';//need to give by the constractor 
                $fakeCollectionName = '$gf' . $this->config['name'] . '_' .  $this->action . '_' . $this->config['file_type'] . '_' . $date;
                $seq = Billrun_Factory::db()->countersCollection()->createAutoInc(array(), $minValue, $fakeCollectionName);
                if ($seq > $maxValue) {
                    $message = "Sequence exceeded max value when generating file for file type " . $this->config['file_type'];
                    throw new Exception($message);
                }
                if (isset($paramObj['padding'])) {
                    $seq = $this->padSequence($seq, $paramObj['padding']);
                }
                return $seq;
			case 'number_of_records':
				return count($this->rowsToExport);
            default:
                $message = "Unsupported filename_params type for file type " . $this->config['file_type'];
                Billrun_Factory::log($message, Zend_Log::ERR);
                break;
        }
    }
	
	protected function padSequence($seq, $padding) {
		
		if(isset($padding['character'])){
			$padChar = $padding['character'];
			$padDir = isset($padding['direction']) ? $padding['direction'] : STR_PAD_LEFT;
			switch ($padDir) {
				case 'right':
					$padDir = STR_PAD_RIGHT;
				case 'left':
				default:
					$padDir = STR_PAD_LEFT;
			}
			$length = isset($padding['length']) ? $padding['length'] : strlen($seq);
			$input = (string)$seq;
			return str_pad($input, $length, $padChar, $padDir);
		}
		return $seq;
    }
	
	/**
	 * get rows to be exported
	 * 
	 * @return array
	 */
	protected function loadRows() {
		$collection = $this->getCollection();
		Billrun_Factory::dispatcher()->trigger('ExportBeforeLoadRows', array(&$this->query, $collection, $this));
		$rows = $collection->query($this->query)->cursor();
		$data = array();
		foreach ($rows as $row) {
			$rawRow = $row->getRawData();
			$this->rawRows[] = $rawRow;
			//$this->rowsToExport[] = $this->getRecordData($rawRow);
			$data[] = $this->getDataLine($rawRow); //maybe - $this->getRecordData($rawRow);
		}
		Billrun_Factory::dispatcher()->trigger('ExportAfterLoadRows', array(&$this->rawRows, &$this->rowsToExport, $this));
		return $data;
	}
	
	/**
	 * gets collection to load data from DB
	 * 
	 * @return string
	 */
	protected function getCollection() {
		if (is_null($this->collection)) {
			$querySettings = $this->config['filtration'][0]; // TODO: currenly, supporting 1 query might support more in the future
			$collectionName = $querySettings['collection'];
			$this->collection = Billrun_Factory::db()->{"{$collectionName}Collection"}();
		}
		return $this->collection;
	}
	
	/**
	 * get file path
	 */
	protected function getFilePath() {
		$sharedPath = Billrun_Util::getBillRunSharedFolderPath(Billrun_Util::getIn($this->config, 'workspace', 'workspace'));
		return rtrim($sharedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . date("Ym") . DIRECTORY_SEPARATOR . substr(md5(serialize($this->config)), 0, 7);
	}
	
	public function shouldFileBeMoved() {
        $localPath = $this->localDir . '/' . $this->getFilename();
        if (file_exists($localPath) && !empty(file_get_contents($localPath))) {
            return true;
        }
        if (file_exists($localPath)) {
            Billrun_Factory::log("Removing empty generated file", Zend_Log::DEBUG);
            $this->removeEmptyFile();
        }
        return false;
    }

    protected function removeEmptyFile() {
        $localPath = $this->localDir . '/' . $this->getFilename();
        $ret = unlink($localPath);
        if ($ret) {
            Billrun_Factory::log()->log('Empty file ' . $localPath . ' was removed successfully', Zend_Log::INFO);
            return;
        }
        Billrun_Factory::log()->log('Failed removing empty file ' . $localPath, Zend_Log::WARN);
    }

    public function move() {
        $exportDetails = $this->configByType['export'];
        $connection = Billrun_Factory::paymentGatewayConnection($exportDetails);
        $fileName = $this->getFilename();
		$res = $connection->export($fileName);
		if (!$res) {
			Billrun_Factory::log()->log('Failed moving file ' . $fileName, Zend_Log::ALERT);
		}
	}
	
	/**
	 * Get the type name of the current object.
	 * @return string conatining the current.
	 */
	public function getType() {
		return static::$type;
	}
	
	abstract protected function getLinkedEntityData($entity, $params, $field);
	
	abstract public function generate();
	
	
	//public static function getInstance
	
}

