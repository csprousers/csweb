<?php

namespace App\CSPro;


use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use App\CSPro\VectorClock;
use App\CSPro\SyncHistoryEntry;
use App\CSPro\Dictionary;
use App\CSPro\Dictionary\Level;
use App\CSPro\Dictionary\Record;
use App\CSPro\Dictionary\Item;
use App\CSPro\Dictionary\ValueSet;
use App\CSPro\Dictionary\Value;
use App\CSPro\Dictionary\ValuePair;
use App\CSPro\Dictionary\JsonDictionaryParser;
use App\CSPro\Dictionary\Parser;
use App\CSPro\Data;

class DictionaryHelper {

    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger, private $serverDeviceId) {

    }

    public function tableExists($table) {
        try {
            $result = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception) {
            return false;
        }
        // ALW - By default PDO will not throw exceptions, so check result also.
        return $result !== false;
    }

    function dictionaryExists($dictName) {
        $stm = 'SELECT id FROM cspro_dictionaries WHERE name = :dictName;';
        $bind = ['dictName' => ['dictName' => $dictName]];
        return $this->pdo->fetchValue($stm, $bind);
    }

    public static function throwDictionaryNotExistError($dictName) {
        throw new HttpException(404, "Dictionary {$dictName} does not exist.");
    }

    function checkDictionaryExists($dictName): int {
        if (($dictId = $this->dictionaryExists($dictName)) == false) {
            self::throwDictionaryNotExistError($dictName);
        }
        return $dictId;
    }

    public function checkCaseExists($dictName, $uuid) {
        try {
            $this->checkDictionaryExists($dictName);
            $strIN = '(UNHEX(REPLACE(' . "'$uuid'" . ',"-","")))';
            $result = $this->pdo->query("SELECT 1 FROM {$dictName} WHERE `uuid` IN {$strIN} ");
        } catch (\Exception) {
            return false;
        }
        return $result !== false;
    }

    public function getDictionaryBinaryDataFolder(string $dictName, string $internalFilesFolder): string {
        return $internalFilesFolder . DIRECTORY_SEPARATOR . 'binary-data' . DIRECTORY_SEPARATOR . strtolower($dictName);
    }

    function loadDictionary($dictName) {
        //sanitize dictionary name
        $dictName = empty($dictName) ? '' : htmlspecialchars(strip_tags($dictName), ENT_QUOTES, 'UTF-8');
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $bFound = false;
            $dict = apcu_fetch($dictName, $bFound);
            if ($bFound == true)
                return $dict;
        }
        $stm = 'SELECT dictionary_full_content FROM cspro_dictionaries WHERE name = :dictName;';
        $bind = ['dictName' => ['dictName' => $dictName]];
        $dictText = $this->pdo->fetchValue($stm, $bind);
        if ($dictText == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }

        if (JsonDictionaryParser::isValidJSON($dictText)) {
            $parser = new JsonDictionaryParser();
        } else {
            $parser = new Parser ();
        }
        try {
            $dict = $parser->parseDictionary($dictText);
            if (extension_loaded('apcu') && ini_get('apc.enabled')) {
                apcu_store($dictName, $dict);
            }
            return $dict;
        } catch (\Exception $e) {
            $this->logger->error('Failed loading dictionary: ' . $dictName, ["context" => (string) $e]);
            throw new HttpException(400, 'dictionary_invalid: ' . $e->getMessage());
        }
    }

    function getKeyStructure($dictionary): string {
        $level = $dictionary->getLevels()[0];
        $keyStructure = "";
        //"length" value + "contentType" value (serialized as in JSON) + [ contentType != "numeric" ? "" : zeroFill ? 1 : 0 ]
        //loop through id items
        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $item = $level->getIdItems()[$iItem];
            $type =  $item->getDataType();
            $dataType = $item->getDataType();
            $itemLength = $item->getLength();
            $keyStructure .= "$itemLength" ."$dataType";
            if ($item->isNumeric()) {
                $keyStructure .= $item->getZeroFill() === true ? 1 : 0;
            }
        }
        return $keyStructure;
    }

    function getPossibleLatLongItemList($dictionary) {
        //loop through single record items including ID items for items that are decimal with at least one decimal digit.
        $level = $dictionary->getLevels()[0];
        $result = [];

        //loop through id items
        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $item = $level->getIdItems()[$iItem];
            if ($item->isNumeric() && $item->getDecimalPlaces() > 0) {
                $result[$item->getName()] = $item->getLabel();
            }
        }
        //loop through single records and get the decimal items
        for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
            $record = $level->getRecords()[$iRecord];
            if ($record->getMaxRecords() == 1) {
                for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
                    $item = $record->getItems()[$iItem];
                    if ($item->isNumeric() && $item->getDecimalPlaces() > 0) {
                        $result[$item->getName()] = $item->getLabel();
                    }
                }
            }
        }
        return $result;
    }

    function getItemsForMapPopupDisplay($dict) {

        $popupItemsMap = [];
        //getIdItems
        $level = $dict->getLevels()[0];

        $idItemArray = [];
        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $idItem = $level->getIdItems()[$iItem];
            $idItemArray[strtoupper($idItem->getName())] = $idItem->getLabel();
        }
        $popupItemsMap["Record"][] = ['id' => 'Id Items', 'items' => $idItemArray];
        for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
            $record = $level->getRecords()[$iRecord];
            if ($record->getMaxRecords() === 1) { //only single records.
                $recordName = strtoupper($record->getName());
                $nameItemMap = [];
                $this->getRecordItemsNameMap($record, $nameItemMap);
                $itemNames = array_keys($nameItemMap);
                $itemArray = [];
                foreach ($itemNames as $itemName) {
                    $itemArray[strtoupper($itemName)] = $nameItemMap[$itemName]->getLabel();
                }
                $popupItemsMap["Record"][] = [$recordName => $record->getLabel(), 'items' => $itemArray];
            }
        }

        return $popupItemsMap;
    }

    function createDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $dictActualName = $dict->getDictionaryName();
        $dictLabel = $dict->getLabel();
        $dictKeyStructure = $this->getKeyStructure($dict);

        if ($this->dictionaryExists($dictName)) {
            $csproResponse->setError(405, 'dictionary_exists', "Dictionary {$dictName} already exists.");
            $csproResponse->setStatusCode(405);
            return;
        }
        // Make sure dict name contains only valid chars (letters, numbers and _)
        // This matches CSPro valid names and protects against SQL injection.
        // Note that PDO does not support using a prepared statement with table name as
        // parameter.
        if (!preg_match('/\A[A-Z0-9_]*\z/', $dictName)) {
            $csproResponse->setError(400, 'dictionary_name_invalid', "{$dictName} is not a valid dictionary name.");
            $csproResponse->setStatusCode(400);
            return;
        }

        $sql = <<<EOT
    CREATE TABLE IF NOT EXISTS `$dictName` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT UNIQUE,
    `uuid` binary(16) NOT NULL,
    `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
    `label` text COLLATE utf8mb4_unicode_ci,
    `note` text COLLATE utf8mb4_unicode_ci,
    `deleted` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `partial_save_mode` varchar(6) NULL,
    `questionnaire` BLOB NOT NULL,
	`revision` int(11) unsigned NOT NULL,
    `clock` text COLLATE utf8mb4_unicode_ci NOT NULL,
    `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_time` timestamp DEFAULT '1971-01-01 00:00:00',
EOT;

        $trigName = 'tr_' . $dictName;
        $sql .= <<<EOT
	PRIMARY KEY (`uuid`),
  	KEY `revision` (`revision`),
  	KEY `key` (`key`),
  	KEY `deleted` (`deleted`),
        INDEX idx_modified_time (modified_time)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
	CREATE TRIGGER IF NOT EXISTS $trigName BEFORE INSERT ON  $dictName FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
        $this->pdo->exec($sql);

        $stmt = $this->pdo->prepare("INSERT INTO cspro_dictionaries (name, `dictionary_actual_name`,
								`dictionary_label`, `dictionary_full_content`, `dictionary_key_structure`) VALUES (:name,:originalName,:label,:content, :keyStructure)");
        $stmt->bindParam(':name', $dictName);
        $stmt->bindParam(':originalName', $dictActualName);
        $stmt->bindParam(':label', $dictLabel);
        $stmt->bindParam(':content', $dictContent);
        $stmt->bindParam(':keyStructure', $dictKeyStructure);
        $stmt->execute();

        $this->createDictionaryBinaryTable($dictName, $csproResponse);
        if ($csproResponse->getStatusCode() != 200) {
            $this->logger->debug('createDictionaryCasesBinaryTable: getStatusCode.' . $csproResponse->getStatusCode());
            return $csproResponse; // failed to create cases binary table.
        }

        $csproResponse = $csproResponse->setContent(json_encode(["code" => 200, "description" => 'Success']));
        $csproResponse->setStatusCode(200);
    }

    function createDictionaryBinaryTable($dictName, &$csproResponse) {
        //Table to store cases and binary items association similar to case-binary-data in csdb
        $casesBinaryTableName = $dictName . '_case_binary_data';

        $sql = <<<EOT
	CREATE TABLE IF NOT EXISTS `$casesBinaryTableName` (
	`id` SERIAL PRIMARY KEY ,
	`case_uuid` binary(16)  NOT NULL,
	`binary_data_signature` char(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	`modified_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	`created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (`case_uuid`)
        REFERENCES `$dictName`(`uuid`)
		ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
        try {
            $this->pdo->exec($sql);
        } catch (\Exception $e) {
            $this->logger->error('Failed creating dictionary case binary table: ' . $casesBinaryTableName, ["context" => (string) $e]);
            $csproResponse->setError(405, 'case_binary_table_createfail', $e->getMessage());
        }
    }

    function updateExistingDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $actualName = $dict->getDictionaryName();
        $dictLabel = $dict->getLabel();
        $dictKeyStructure = $this->getKeyStructure($dict);
        try {
            // Update dictionaries table with new label and content
            $stmt = $this->pdo->prepare("UPDATE cspro_dictionaries SET `dictionary_label`=:label, `dictionary_actual_name` =:originalName,`dictionary_full_content`=:content, `dictionary_key_structure`=:keyStructure WHERE name=:name");
            $stmt->bindParam(':name', $dictName);
            $stmt->bindParam(':originalName', $actualName);
            $stmt->bindParam(':label', $dictLabel);
            $stmt->bindParam(':content', $dictContent);
            $stmt->bindParam(':keyStructure', $dictKeyStructure);
            $stmt->execute();

            $csproResponse = $csproResponse->setContent(json_encode(["code" => 200, "description" => 'Success']));
            $csproResponse->setStatusCode(200);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update dictionary: ' . $dictName, ["context" => (string) $e]);
            $csproResponse->setError(500, 'dictionary_update_fail', $e->getMessage());
        }
    }

    function getLastSyncForDevice($dictName, $device) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history '
                . 'JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE device=:device AND name = :dictName ORDER BY revision DESC LIMIT 1';
            $bind = ['device' => ['device' => $device], 'dictName' => ['dictName' => $dictName]];
            $entry = $this->pdo->fetchOne($stm, $bind);
            return $entry ? (Object) $entry : null;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLastSyncForDevice ' . $dictName, 0, $e);
        }
    }

    function getSyncHistoryByRevisionNumber($dictName, $revisionNumber) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history '
                . 'JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE revision = :revisionNumber AND name = :dictName';
            $bind = ['revisionNumber' => ['revisionNumber' => $revisionNumber], 'dictName' => ['dictName' => $dictName]];
            $entry = $this->pdo->fetchOne($stm, $bind);
            return $entry ? (Object) $entry : null;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getSyncHistoryByRevisionNumber ' . $dictName, 0, $e);
        }
    }

    // is universe more restrictive Or same as the previous revision
    function isUniverseMoreRestrictiveOrSame($currentUniverse, $lastRevisionUniverse) {
        // if the current universe is a sub string of last revision universe, they are not the same
        if ($currentUniverse === $lastRevisionUniverse) {
            return true;
        } else {
            return (strlen($currentUniverse) >= strlen($lastRevisionUniverse)) && str_starts_with($currentUniverse, $lastRevisionUniverse);
        }
    }

    /**
     * Assocciates
     * @param string $dictName
     * @param array $caseBinaryItems Case and binary items association as hashmap of uuid => array of signatures
     * @return void
     * @throws \Exception
     */
    function associateCaseBinaryItems($dictName, $caseBinaryItems) : void{
        $caseBinaryDataTableName = $dictName . '_case_binary_data';
//call this only when dictionary has binary items
        //delete existing case binary item associations and reinsert new to ensure that the case binary association items are in sync
        $caseIdList = array_keys($caseBinaryItems);

        //$strCaseList = "'" . implode("','", $caseIdList) . "'";
        $strCaseList = implode(",", array_map(fn($uuid) => 'UNHEX(REPLACE(' . "'$uuid'" . ',"-",""))', $caseIdList));

        $stm = "DELETE FROM $caseBinaryDataTableName WHERE `case_uuid` in ( " . $strCaseList . ")";
        try {
            $this->pdo->query($stm); //delete the case binary item associations
            if (count($caseBinaryItems) == 0)
                return;
            $stm = 'INSERT INTO ' . $caseBinaryDataTableName . ' (`case_uuid`, `binary_data_signature`) VALUES ';
            $n = 0;
            $insertQuery = array();
            $insertData = array();
            foreach ($caseBinaryItems as $key => $value) {
                foreach ($value as $signature) {
                    $uuidFormat = '(UNHEX(REPLACE(:case_uuid' . $n . ',"-",""))';
                    $insertQuery [] = "$uuidFormat, :binary_data_signature$n)";
                    $insertData ['case_uuid' . $n] = $key;
                    $insertData ['binary_data_signature' . $n] = $signature;
                    $n++;
                }
            }
            if (!empty($insertQuery)) {
                $stm .= implode(', ', $insertQuery);
                $stmt = $this->pdo->prepare($stm);
                $result = $stmt->execute($insertData); // true if successful
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed updating case binary items for dictionary: ' . $dictName, ['context' => (string) $e]);
            throw new \Exception('Failed updating case binary items for dictionary: ' . $dictName, 0, $e);
        }
    }

    function addBinarySyncHistoryEntry($dictName, $binaryItems, $syncHistoryId) {
        $stm = 'INSERT INTO `cspro_binary_sync_history`(`binary_data_signature`,`sync_history_id`) VALUES ';
        $n = 0;
        $insertData ['sync_history_id'] = $syncHistoryId;
        $insertQuery = array();
        foreach ($binaryItems as $binaryItem) {
            $insertData ['binary_data_signature' . $n] = $binaryItem;
            $insertQuery [] = "(:binary_data_signature$n, :sync_history_id)";
            $insertData ['binary_data_signature' . $n] = $binaryItem;
            $n++;
        }
        if (!empty($insertQuery)) {
            try {
                $stm .= implode(', ', $insertQuery);
                $stmt = $this->pdo->prepare($stm);
                $result = $stmt->execute($insertData); // true if successful
            } catch (\Exception $e) {
                $this->logger->error('Failed adding binary sync history for dictionary: ' . $dictName, ['context' => (string) $e]);
                throw new \Exception('Failed adding binary sync history for dictionary: ' . $dictName, 0, $e);
            }
        }
    }

    /**
     * Archive the biary sync history entries if a revision from the client does not match the last sent revision and the last sent case uuid
     * @param string $dictId Dictionary ID
     * @param string $deviceId Device ID
     * @param int $lastRevision - Revision (cases) from the client header saying the last revision the client successfully got and saved
     * @param string $startAfterGuid - last sent case uuid from the client header that the client successfully got and saved from previous get
     * @param string $direction - we are doing this only for gets
     * @throws \Exception
     */
    function archiveBinarySyncHistoryEntries($dictId, $deviceId, $lastRevision, $startAfterGuid, $direction = 'get') {

        $bind['dictId'] = $dictId;
        $bind['deviceId'] = $deviceId;
        $bind['direction'] = $direction;
        $bind['last_case_uuid'] = $startAfterGuid;
        $bind['lastRevision'] = $lastRevision;

        if ($lastRevision > 0) {
            //check if the revision number the client says it has matches with the last sync sent to this device
            //if it does not archive the binary sync history sent to this device
            //first get the max revision
            $strGUIDSQL = "";
            if (strlen($startAfterGuid) > 0) {
                $strGUIDSQL = " AND last_case_uuid IS NOT NULL  AND last_case_uuid = UNHEX(REPLACE(:last_case_uuid, '-', ''))";
            }
            $stm = "SELECT T.last_sync_revison FROM
                    (SELECT revision as last_sync_revison FROM cspro_sync_history
                      WHERE dictionary_id = :dictId
                      AND `device` = :deviceId
                      AND direction = :direction
                      AND last_case_revision = :lastRevision
                      $strGUIDSQL)AS T
                    WHERE `last_sync_revison` IN (SELECT max(revision) as max_sync_revison FROM cspro_sync_history WHERE
                    `dictionary_id` = :dictId AND `device`= :deviceId AND `direction`= :direction)";

            $lastSyncRevision = $this->pdo->fetchValue($stm, $bind);
            //if match found do not have to archive.
            if ($lastSyncRevision !== false) {
                $this->logger->debug("Revision found for previous sync with revision: $lastRevision and last synced cased id: $startAfterGuid");
                return;
            }
            $this->logger->info("Revision not found for previous sync with revision: $lastRevision and last synced cased id: $startAfterGuid");
        }

        //if match not found archive all binary items since the last case revision
        //Get the min revision number from sync histor table where last_case_revision >= lastCaseRevision sent to this device
        $stm = "SELECT MIN(revision) as min_sync_revison FROM `cspro_sync_history` WHERE
                    `dictionary_id` = :dictId AND `device`= :deviceId AND `direction`= :direction AND last_case_revision >= :lastRevision";
        $this->pdo->perform($stm, $bind);
        $minRevision = $this->pdo->fetchValue($stm, $bind);
        if ($minRevision == false) {
            $minRevision = 0;
        }
        $this->logger->info("Archiving binary sync history items for syncs since revision: $minRevision");
        //move the items to cspro_binary_sync_history_archive table
        $bind['minRevision'] = $minRevision;
        $stm = "INSERT INTO `cspro_binary_sync_history_archive`(`binary_data_signature`,`sync_history_id`)
                SELECT `binary_data_signature`,`sync_history_id` FROM `cspro_binary_sync_history` CBSH
				JOIN `cspro_sync_history` CSH ON  CSH.`revision` = CBSH.`sync_history_id`
                WHERE (`device` = :deviceId AND dictionary_id=:dictId AND direction=:direction AND sync_history_id >= :minRevision);";
        try {
            $this->pdo->beginTransaction();
            $this->pdo->perform($stm, $bind);
            $lastRevisionId = $this->pdo->lastInsertId();

            //delete the gets in cspro_binary_sync_history  table after archiving the items
            $stm = "DELETE CBSH
                    FROM `cspro_binary_sync_history` CBSH
                    JOIN `cspro_sync_history` CSH ON CSH.`revision` = CBSH.`sync_history_id`
            WHERE(`device` = :deviceId AND dictionary_id = :dictId AND direction = :direction  AND sync_history_id >= :minRevision)";
            $this->pdo->perform($stm, $bind);
            $this->pdo->commit();
        } catch (\Exception $e) {
             if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \Exception('Failed to archive binary sync history ', 0, $e);
        }
    }

    /**
     *
     * @param array $case binary items for the case that needs be send for download
     * @param string $dictName
     * @param int $dictId
     * @param string $deviceId
     * @param array $caseBinaryItemMap caseGUD=> array of md5 signatures for the case that need to be sent
     * @param string $binaryItemSignatures
     * @return int running total of binary content size
     *  *
     */
    function getBinaryCaseItemsForWrite($case, $dictName, $dictId, $deviceId, $binaryItemsDirectory, &$caseBinaryItemMap, &$binaryItemSignatures): int {
        /*
         * {
          "length": 32000,
          "caseid": "797e52bd-17ad-477f-8aed-6babe4db7c6c",
          "signature": "1789AFBE3742C6755708BD02798B98F1"
          }
         */
        //Get the md5 signatures for case that have not been sent to this device
        $totalBinaryContentSize = 0;
        $bind['caseId'] = $caseId = $case['uuid'];
        $bind['dictId'] = $dictId;
        $bind['deviceId'] = $deviceId;
        $binaryDataTableName = $dictName . '_case_binary_data';
        //select binary items for this case that have either never been sent to this device
        //it is likely that a binary item content got from this device may be sent at most once again when it is linked to a different case
        //to ignore puts from the device we will not be able to identify after sending it using one csdb file, downloads the cases to a different
        //csdb file. In this case, we have to resend the binary content even though we got the cases through put from this device
        //hence using get the direction to avoid sending duplicate binary case content.
        $sql = <<<EOT
                SELECT DISTINCT
                    T1.`binary_data_signature` AS signature
                FROM
                    (SELECT
                        `binary_data_signature`
                    FROM
                        `{$binaryDataTableName}`
                    WHERE
                        `{$binaryDataTableName}`.`case_uuid` = UNHEX(REPLACE(:caseId, '-', ''))) AS T1
                        LEFT JOIN
                    (`cspro_binary_sync_history`
                    JOIN `cspro_sync_history` ON `cspro_sync_history`.`revision` = `cspro_binary_sync_history`.`sync_history_id`
                        AND `dictionary_id` = :dictId
                        AND `device` = :deviceId
                        ) ON `T1`.`binary_data_signature` = `cspro_binary_sync_history`.`binary_data_signature`
                        WHERE `direction` <> 'get' OR  `direction` IS NULL;
                EOT;
        $result = $this->pdo->fetchAll($sql, $bind);
        if (count($result) > 0) {
            foreach ($result as $row) {
                //do not add the binary item for sending if it has been marked to be sent in this feed in another case
                if (!isset($binaryItemSignatures[$row['signature']])) {
                    $md5 = $row['signature'];
                    $binaryFilePath = $binaryItemsDirectory . DIRECTORY_SEPARATOR . $md5;
                    $fileExists = file_exists($binaryFilePath);
                    if ($fileExists !== true) {
                        $this->logger->error("Binary item file content not found at $binaryFilePath");
                        continue; //for now sending cases even if the binary content is not found
                    }
                    $contentLength = filesize($binaryFilePath);
                    $binaryItem['length'] = $contentLength;
                    $binaryItem['uuid'] = $caseId;
                    $binaryItem['signature'] = $md5;
                    unset($binaryItem['metadata']);
                    //set the metadata key as you traverse and once your find the correct binary item return
                    $caseData = json_decode($case['case'], true);
                    $binaryItemJSON = $this->findBinaryItemJSON($caseData, $md5);
                    if (count($binaryItemJSON) > 0) {
                        $binaryItem['metadata'] = $binaryItemJSON['metadata'];
                    }
                    //get the binary json string that needs to be written out from the case
                    //assume the file is availabe on the disc and compute the length from binary item json
                    $binaryItemSignatures[$md5] = $contentLength;
                    $caseBinaryItemMap[$caseId][] = $binaryItem;

                    $totalBinaryContentSize += $contentLength;
                }
            }
        }
        return $totalBinaryContentSize;
    }

    /**
     *
     */
    function findBinaryItemJSON($array, $signature) {
        $values = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $nestedKeys = array_keys($value);
                if (in_array("signature", $nestedKeys) && in_array("metadata", $nestedKeys)) {
                    if ($value['signature'] == $signature && count($values) == 0) {
                        $values['metadata'] = $value['metadata'];
                    }
                } else {
                    if (count($values) == 0) {
                        $nestedValues = $this->findBinaryItemJSON($value, $signature);
                        if (isset($nestedValues['metadata']))
                            $values['metadata'] = $nestedValues['metadata'];
                    }
                }
            }
        }
        return $values;
    }

    /**
     *
     * @param array $caseList list of cases as associative array
     * @return array Case and binary items association as map of uuid => array of signatures
     */
    function getBinaryCaseItems($caseList): array {
        $caseBinaryItems = array();
        foreach ($caseList as $case) {
            $signatureArray = array();
            $caseId = $case['uuid'];
            //Now Case is already an associative array no need to decode
            //$case = json_decode($case['case'], true);

            array_walk_recursive($case, function ($value, $key) use (&$signatureArray) {
                if (strcmp($key, 'signature') == 0 && strlen($value) > 0) {
                    $signatureArray[] = $value;
                }
            }, $signatureArray);
            $uniqueSignatures = array_unique($signatureArray);
            if (count($uniqueSignatures) > 0) {
                $caseBinaryItems[$caseId] = $uniqueSignatures;
            }
        }
        return $caseBinaryItems;
    }

    // Add a new sync history entry to database and return the revision number
    function addSyncHistoryEntry($deviceId, $userName, $dictName, $direction, $lastCaseRevision = 0, $lastCaseID = "", $universe = ""): int {
        //SELECT dictionary ID
        $dictId = $this->dictionaryExists($dictName);
        if ($dictId == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }
        // insert a row into the sync history with the new version
        if (strlen($lastCaseID) > 0) {
            $stm = 'INSERT INTO cspro_sync_history (`device` , `username`, `dictionary_id`, `direction`, `universe`, last_case_revision,last_case_uuid)
			 VALUES (:deviceId, :userName, :dictionary_id, :direction, :universe, :last_case_revision, UNHEX(REPLACE(:case_uuid' . ',"-","")))';
        } else {

            $stm = 'INSERT INTO cspro_sync_history (`device` , `username`, `dictionary_id`, `direction`, `universe`)
			 VALUES (:deviceId, :userName, :dictionary_id, :direction, :universe)';
        }

        $bind = array();
        $bind['deviceId'] = $deviceId;
        $bind['userName'] = $userName;
        $bind['dictName'] = $dictName;
        $bind['universe'] = $universe;
        $bind['direction'] = $direction;
        $bind['dictionary_id'] = $dictId;
        $bind['last_case_revision'] = $lastCaseRevision;
        $bind['case_uuid'] = $lastCaseID;
        try {
            $this->pdo->perform($stm, $bind);
            $lastRevisionId = $this->pdo->lastInsertId();
            return  $lastRevisionId !== false ? (int) $lastRevisionId : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to addSyncHistoryEntry: ' . $dictName, ["context" => (string) $e]);
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \Exception('Failed to addSyncHistoryEntry ' . $dictName, 0, $e);
        }
    }

    //Delete Sync history entry
    function deleteSyncHistoryEntry($revision) {
        // delete entry in sync history for the revision
        $stm = $stm = 'DELETE FROM `cspro_sync_history` WHERE revision=:revision';
        $bind = ['revision' => ['revision' => $revision]];
        $deletedSyncHistoryCount = $this->pdo->fetchAffected($stm, $bind);
        $this->logger->debug('Deleted # ' . $deletedSyncHistoryCount . ' Sync History Entry revision: ' . $revision);
        return $deletedSyncHistoryCount;
    }

    // Select all the cases sent by the client that exist on the server
    function getLocalServerCaseList($dictName, $caseList) {
        if ((is_countable($caseList) ? count($caseList) : 0) == 0)
            return null;

        try {
            $this->checkDictionaryExists($dictName);
            // Select all the cases sent by the client that exist on the server
            $stm = 'SELECT  LCASE(CONCAT_WS("-", LEFT(HEX(uuid), 8), MID(HEX(uuid), 9,4), MID(HEX(uuid), 13,4), MID(HEX(uuid), 17,4), RIGHT(HEX(uuid), 12))) as uuid,
			clock
			FROM ' . $dictName;
            $insertData = [];
            $ids = [];
            $strWhere = '';
            $n = 1;
            foreach ($caseList as $row) {
                $insertData [] = 'UNHEX(REPLACE(:uuid' . $n . ',"-",""))';
                $ids ['uuid' . $n] = $row ['uuid'];
                $n++;
            }
            // do bind values for the where condition
            if (count($insertData) > 0) {
                $inQuery = implode(',', $insertData);
                $stm .= ' WHERE `uuid` IN (' . $inQuery . ');';
            }

            $stmt = $this->pdo->prepare($stm);

            $stmt->execute($ids);
            $result = $stmt->fetchAll();

            $localServerCases = [];
            foreach ($result as $row) {
                $localServerCases [$row ['uuid']] = $row;
            }
            return $localServerCases;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLocalServerCaseList ' . $dictName, 0, $e);
        }
    }

    function reconcileCases(&$caseList, $localServerCases) {
        // fix the caseList.
        $defaultServerClock = new VectorClock(null);
        $defaultServerClock->setVersion($this->serverDeviceId, 1);
        $defaultJSONArrayServerClock = json_decode($defaultServerClock->getJSONClockString(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($caseList as $i => &$row) {
            $serverCase = isset($localServerCases, $localServerCases [$row ['uuid']]) ? $localServerCases [$row ['uuid']] : null;
            if (isset($serverCase)) {
                $strJSONServerClock = $serverCase ['clock'];
                $serverClock = new VectorClock(json_decode($strJSONServerClock, true, 512, JSON_THROW_ON_ERROR));
                $clientClock = new VectorClock($row ['clock']); // the caselist row has decoded json array for the clock
                // compare clocks
                if ($clientClock->IsLessThan($serverClock)) {
                    // Local server case is more recent, do not update
                    // Remove this case from the $caseList.
                    // echo 'client clock less than server clock';
                    unset($caseList [$i]);
                    continue;
                } else if ($serverClock->IsLessThan($clientClock)) {
                    // Update is newer, replace the local server case
                    // do nothing. $row in the caseList will update the server case. client clock will be updated on the server.
                    // echo 'server clock less than client clock';
                } else if (!$serverClock->IsEqual($clientClock)) {
                    // Conflict - neither clock is greater - always use the client case and merge the clocks
                    // merge the clocks
                    // echo 'conflict! ';
                    $serverClock->merge($clientClock);
                    // update the case using the merged clock
                    $row ['clock'] = json_decode($serverClock->getJSONClockString(), true, 512, JSON_THROW_ON_ERROR);
                }
            }
            if ((is_countable($row ['clock']) ? count($row ['clock']) : 0) == 0) { // set the server default clock for updates or inserts if the clock sent is empty
                $row ['clock'] = $defaultJSONArrayServerClock;
            }
        }
        unset($row);
        // remove cases that have been discarded.
        $caseList = array_filter($caseList);
    }

    function prepareJSONForInsertOrUpdate($dictName, &$caseList) {
        // for each row get the record list array to multi-line string for the questionnaire data
        // Get the clocks for the cases on the server.
        // get local server cases
        $localServerCases = $this->getLocalServerCaseList($dictName, $caseList);
        // reconcile server cases with the client
        $this->reconcileCases($caseList, $localServerCases);
        foreach ($caseList as &$row) {
            //https://stackoverflow.com/questions/24607493/mysql-compress-vs-php-gzcompress
            //php gzcompress and MySQL uncompress differ in the header the static header below works fine
            //with 4 bytes  zlib.org/rfc-gzip.html, with header 1F 8B 08 00 = ID1|ID2|CM |FLG
            //if this has issues in future use the commented line below which adds the leading 4 bytes with original size of the string
//                  $insertData ['questionnaire' . $n] = pack('V', mb_strlen($row ['level-1'])) . gzcompress($row ['level-1']); // CSPro 7.5+
            $preparedRow = [];
            $preparedRow ['case'] = "\x1f\x8b\x08\x00" . gzcompress(json_encode($row, JSON_THROW_ON_ERROR));//now storing the entire case
            $preparedRow ['uuid'] = $row['uuid'];
            $preparedRow ['key'] = $row['key'];
            $preparedRow ['deleted'] = ( isset($row ['deleted']) && (1 == $row ['deleted'])) ? true : false;
            $preparedRow ['verified'] = (isset($row ['verified']) && (1 == $row ['verified'])) ? true : false;
            $preparedRow ['clock'] = json_encode($row ['clock'], JSON_THROW_ON_ERROR); // convert the json array clock to json string
            $preparedRow ['label'] = $row['label']??'';
            $preparedRow ['caseNote'] = $row['caseNote']??'';
            $preparedRow ['partial_save_mode'] = $row ['partialSave']['mode']??null;
            $row = $preparedRow;
        }
        unset($row);
    }

    function isJsonQuestionnaire($case) {
        $len = strlen($case);
        return $len >= 2 && $case[0] == '{' && $case[$len - 1] == '}';
    }

    function prepareResultSetForJSON(&$caseList): array {
        // for each row get the record list array to multi-line string for the questionnaire data
        //return array of revisions for cases
        $caseRevisions = array();
        foreach ($caseList as &$row) {
            if (isset($row['revision'])) {
                $caseRevisions[] = $row['revision'];
            }
            unset($row['revision']);
            $row['case'] = gzuncompress(substr($row['case'], 4));
            if (isset($row ['lastModified'])) {
                $lastModifiedUTC = \DateTime::createFromFormat('Y-m-d H:i:s', $row ['lastModified'], new \DateTimeZone("UTC"));
                $row ['lastModified'] = $lastModifiedUTC->format(\DateTime::RFC3339);
            }
        }
        unset($row);
        return $caseRevisions;
    }

    /**
     * writes case json to binary stream and accumulates the list of cases to send up to the limit of total content size of binary items if they exist
     * @param resource $stream
     * @param Request $request
     * @param string $dictName
     * @param array $bind
     * @param string $strWhere
     * @param string $strUniverse
     * @param int $maxRevisionForChunk
     * @param int $rangeCount
     * @param bool $isBinaryJSON
     * @param array $caseBinaryItemMap
     * @return array
     */
    function writeCasesJSONToStream(&$stream, $request, $dictName, $deviceId, $binaryItemsDirectory, $bind, $strWhere, $strUniverse, $maxRevisionForChunk, $rangeCount,
            $isBinaryJSON, &$caseBinaryItemMap, $maxPacketSize = 50 * 1024 * 1024): array {
        // return the cases that were added or modified since the lastRevision up to the maxrevision for chunk
        $returnValues = array();
        $returnValues['totalCases'] = $rangeCount;
        $returnValues['maxRevisionForChunk'] = $maxRevisionForChunk;

        $bind['maxRevision'] = $maxRevisionForChunk;
        $maxCasesPerQuery = 10000; //Limit queries to 10000 rows in each request
        $limit = $maxCasesPerQuery;
        $dictId = $this->checkDictionaryExists($dictName);

        if ($rangeCount > 0 && $rangeCount < $limit)
            $limit = $rangeCount;
        $bind['limit'] = $limit;

        // the statement to prepare
        $strQuery = 'SELECT `id` as `position`, LCASE(CONCAT_WS("-", LEFT(HEX(uuid), 8), MID(HEX(uuid), 9,4), MID(HEX(uuid), 13,4), MID(HEX(uuid), 17,4), RIGHT(HEX(uuid), 12))) as uuid,
								   questionnaire as `case`, revision FROM ';
        $strOrderBy = ' ORDER BY  revision,  uuid  LIMIT :limit ';
        $stm = $strQuery . $dictName . $strWhere . $strUniverse . $strOrderBy;

        // $this->logger->debug('query: ' . $stm);
        // do bind values. when universe or limit exist
        $casesJSONStream = fopen('php://temp', 'r+');

        $strJSONResponse = '[';
        $totalCases = 0;
        $totalBinaryContentSize = 0;

        while (true) {
            //query the DB  up to the #limit number of rows
            $result = $this->pdo->fetchAll($stm, $bind);

            if (count($result) > 0) {//the next iteration should use the caseid after the last retrieved
                ///get the revision for this uuid
                $bind['case_uuid'] = $result[count($result) - 1]['uuid'];
                $bind['lastRevision'] = $result[count($result) - 1]['revision'];
                $strWhere = ' WHERE ((revision = :lastRevision AND  uuid > (UNHEX(REPLACE(:case_uuid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
                $stm = $strQuery . $dictName . $strWhere . $strUniverse . $strOrderBy;
            }


            // for each row
            $caseRevisions = $this->prepareResultSetForJSON($result);
            $numCases = count($result);
            $binaryContentLimitReached = false;
            if ($isBinaryJSON) {//limit the result to
                $binaryItemSignatures = array();
                $numCases = 0;
                foreach ($result as $case) {
                    /* if (strcmp($case['id'], '7654d2d6-7d22-4b89-97c6-0d981ab3432f') !== 0)
                      continue; */
                    $numCases++;
                    $this->logger->debug("Case# $numCases with caseId: " . $case['uuid']);
                    $totalBinaryContentSize += $this->getBinaryCaseItemsForWrite($case, $dictName, $dictId, $deviceId, $binaryItemsDirectory, $caseBinaryItemMap, $binaryItemSignatures);
                    if ($totalBinaryContentSize > $maxPacketSize) {
                        $binaryContentLimitReached = true;
                        break;
                    }
                }
                if ($totalBinaryContentSize > $maxPacketSize) {
                    $result = array_slice($result, 0, $numCases, true);
                }
            }

            if (count($caseRevisions) > 0) {//used in binary json header to get the correct chunk as all the cases requested may not be sent
                $returnValues['maxRevisionForChunk'] = $caseRevisions[$numCases - 1];
                $returnValues['lastCaseId'] = $result[$numCases - 1]["uuid"];
                $this->logger->debug("lastCaseID# " . $returnValues['lastCaseId'] . " with chunk maxRevision:" . $returnValues['maxRevisionForChunk']);
            }

            //remove the trailing and leading list chars []
            $casesString = implode(',', array_column($result, 'case'));
            $strJSONResponse .= $casesString;
            //$strJSONResponse .= trim(json_encode($result, JSON_THROW_ON_ERROR), "[]");

            fwrite($casesJSONStream, $strJSONResponse);
            $strJSONResponse = ','; //reset json string response and add the comma for the next batch

            $totalCases += is_countable($result) ? count($result) : 0;
            if ((is_countable($result) ? count($result) : 0) < $limit) //finished processing the cases
                break;

            //if we have sent the requested number of cases break. or if binary content limit reached break
            //when binary content limit is reached we may not be sending the full range count requested
            if ($binaryContentLimitReached || ($rangeCount > 0 && $totalCases >= $rangeCount))
                break;

            //reduce the limit if the limit is over the requested number of cases
            if ($rangeCount > 0)
                $limit = min($rangeCount - $totalCases, $maxCasesPerQuery);

            $bind['limit'] = $limit; //set the new limit in the binding;
        }

        //finalize the strJSONResponse
        $strJSONResponse = trim($strJSONResponse, ",");
        $strJSONResponse .= ']';

        fwrite($casesJSONStream, $strJSONResponse);
        rewind($casesJSONStream);

        //copy cases json stream to input stream
        stream_copy_to_stream($casesJSONStream, $stream);
        fclose($casesJSONStream);
        rewind($stream);

        $returnValues['totalCases'] = $numCases;
        return $returnValues;
    }

    //returns columns in area names table it exists otherwise returns -1
    function getAreaNamesColumnCount() {
        $columnCount = -1;
        $selectStm = "select database()";

        try {
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $databaseName = $query->fetchColumn();
            $selectStm = "SELECT COUNT(*) FROM `information_schema`.`columns` WHERE `table_schema` = '$databaseName' AND `table_name` LIKE '%cspro_area_names%'";
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $columnCount = $query->fetchColumn();
        } catch (\Exception $e) {
            throw new \Exception('Failed getting area names column count ', 0, $e);
            $this->logger->error('Failed getting area names column count ', ["context" => (string) $e]);
        }

        return $columnCount;
    }

    function formatMapMarkerInfo($dict, $caseJSON, $markerItemList) {
        $mapMarkerInfo = [];
        $this->logger->debug("printing marker info " . print_r($markerItemList, true));
        $iLevel = 0;
        $level = $dict->getLevels()[$iLevel];
        $levelName = strtoupper($level->getName());
        if (isset($caseJSON[$levelName])) {
            $mapMarkerInfo["Case"] = $caseJSON["key"];
            $questionnaireJSON = $caseJSON[$levelName];

            //for each item in the id record
            $nameItemMap = [];
            for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
                $this->getRecordItemNameMap($level->getIdItems()[$iItem], $nameItemMap);
            }
            $itemNames = array_keys($nameItemMap);
            $upperItemNames = array_map('strtoupper', $itemNames);

            for ($idItem = 0; $idItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $idItem++) {
                if (array_search($upperItemNames[$idItem], $markerItemList) !== false) {
                    $this->logger->debug("processing item " . $upperItemNames[$idItem]);
                    $value = $questionnaireJSON[$upperItemNames[$idItem]]["code"] ?? "";
                    $mapMarkerInfo[$this->getDisplayText($level->getIdItems()[$idItem])] = $this->getItemValueDisplayText($level->getIdItems()[$idItem], $value);
                }
            }
            //loop through the single records
            for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $recordName = strtoupper($record->getName());
                if ($record->getMaxRecords() === 1) {//multiple records
                    //loop through the records for items
                    for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
                        $item = $record->getItems()[$iItem];
                        $upperItemName = strtoupper($item->getName());
                        if (array_search($upperItemName, $markerItemList) !== false) {
                            //get the item name and value
                            $value = $questionnaireJSON[$recordName][$upperItemName] ?? "";
                            $mapMarkerInfo[$this->getDisplayText($item)] = $this->getItemValueDisplayText($item, $value);
                        }
                    }
                }
            }
        }
        return $mapMarkerInfo;
    }

    function formatCaseJSONtoHTML($dict, $caseJSON): string {
        //for each id item
        $caseHtml = "";
        //TODO: fix for multiple levels
        // $this->logger->debug('printing dictionary: ' . print_r($dict, true));
        $iLevel = 0;
        $level = $dict->getLevels()[$iLevel];
        $levelName = strtoupper($level->getName());
        if (isset($caseJSON[$levelName])) {
            if (isset($caseJSON["key"])) {
                $labelOrKey = isset($caseJSON["label"]) && !empty($caseJSON["label"]) ? trim($caseJSON["label"]) : trim($caseJSON["key"]);
                $caseHtml .= "<p class=\"c2h_level_name\">" . $labelOrKey . "</p>";
            }
            $questionnaireJSON = $caseJSON[$levelName];
            $caseHtml .= $this->formatCaseLevelJSONtoHTML($level, $questionnaireJSON);
            //loop through the records
            for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $record->setLevel($level);
                $caseHtml .= $this->formatRecordJSONtoHTML($record, $questionnaireJSON);
            }
        }

        if (isset($caseJSON['notes'])) {
            $caseHtml .= $this->formatCaseNotetoHTML($caseJSON['notes']);
        }
        return $caseHtml;
    }

    private function formatCaseLevelJSONtoHTML($level, $caseJSON): string {

        $values = [];
        $levelIDsHtml = "";
        $levelIDsHtml .= "<p class=\"c2h_level_name\">" . $this->getDisplayText($level) . "</p>";

        $nameItemMap = [];
        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $this->getRecordItemNameMap($level->getIdItems()[$iItem], $nameItemMap);
        }
        $itemNames = array_keys($nameItemMap);
        $upperItemNames = array_map('strtoupper', $itemNames);

        //write id record header
        $levelIDsHtml .= "<table class=\"c2h_table\">";
        $levelIDsHtml .= "<tr>";
        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $levelIDsHtml .= "<td class=\"c2h_table_header\">";
            $levelIDsHtml .= $upperItemNames[$iItem] . ": " . $nameItemMap[$itemNames[$iItem]]->getLabel() . "</td>";
        }
        $levelIDsHtml .= "</tr>";

        for ($idItem = 0; $idItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $idItem++) {
            if (isset($caseJSON[$upperItemNames[$idItem]]["code"])) {
                $values[] = $caseJSON[$upperItemNames[$idItem]]["code"];
            } else {
                $values[] = "";
            }
        }
        $levelIDsHtml .= $this->formatDataRow($nameItemMap, $values, 1);
        $levelIDsHtml .= "</table>";
        return $levelIDsHtml;
    }

    private function formatRecordJSONtoHTML($record, $caseJson): string {

        $recordList = [];
        $recordHtml = "<p class=\"c2h_record_name\">" . $this->getDisplayText($record) . "</p>";
        $nameItemMap = [];
        $this->getRecordItemsNameMap($record, $nameItemMap);
        $itemNames = array_keys($nameItemMap);

        //write  record header
        $recordHtml .= "<table class=\"c2h_table\">";
        $recordHtml .= "<tr>";
        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $recordHtml .= "<td class=\"c2h_table_header\">";
            $recordHtml .= strtoupper($itemNames[$iItem]) . ": " . $nameItemMap[$itemNames[$iItem]]->getLabel() . "</td>";
        }
        $recordHtml .= "</tr>";

        $upperRecordName = strtoupper($record->getName());
        if (isset($caseJson[$upperRecordName])) {
            //if data rows available
            if (isset($caseJson[$record->getName()])) {
                $recordList = $caseJson[$upperRecordName];

                //for each datarow
                $recordCount = 0;
                $parentItem = null;
                foreach ($recordList as $curRec) {
                    $recordCount++;
                    //set the values array for the record
                    $values = [];
                    //prepare item values
                    for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
                        $item = $record->getItems()[$iItem];
                        if ($item->getItemType() === "Item") {
                            $parentItem = $item;
                            $item->setParentItem(null);
                        } else {
                            $item->setParentItem($parentItem);
                        }
                        $this->fillItemValues($item, $record, $curRec, $values);
                    }
                    //format data row
                    $recordHtml .= $this->formatDataRow($nameItemMap, $values, $recordCount);
                }
            }
        }
        $recordHtml .= "</table>";
        return $recordHtml;
    }

    function formatCaseNotetoHTML($caseNotes): string {
        //for each id item
        $caseNoteHtml = "";
        if ((is_countable($caseNotes) ? count($caseNotes) : 0) == 0) {
            return $caseNoteHtml;
        }

        $caseNoteHtml .= '<p class="c2h_record_name">Notes</p>';
        $caseNoteHtml .= '<table class="c2h_table">';
        $headerList = ['Field', 'Note', 'Operator ID', 'Date/Time'];

        foreach ($headerList as $header) {
            $caseNoteHtml .= "<td class=\"c2h_table_header\">" . $header . "</td>";
        }
        $caseNoteHtml .= "</tr>";
        $index = 0;
        foreach ($caseNotes as $note) {
            $formatClass = "c2h_table_r" . $index % 2;
            $caseNoteHtml .= "<tr>";

            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['name']??''. "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['text']??'' . "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['operatorId']??'' . "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['modifiedTime']??'' . "</td>";

            $caseNoteHtml .= "</tr>";
            $index++;
        }

        $caseNoteHtml .= "</table>";
        return $caseNoteHtml;
    }

    public function fillItemValues(Item $item, Record $record, $curRecord, &$values) {
        $occurs = $item->getItemSubitemOccurs();
        $itemName = strtoupper($item->getName());
        $isNumeric = $item->isNumeric();

        if ($occurs > 1) {
            $itemOccValues = array_fill(0, $occurs, "");
            if (isset($curRecord[$itemName])) {
                $itemValuesArray = $curRecord[$itemName];
                for ($iItemValue = 0; $iItemValue < (is_countable($itemValuesArray) ? count($itemValuesArray) : 0); $iItemValue++) {
                    $itemOccValues[$iItemValue] = $itemValuesArray[$iItemValue]["code"]??null;
                    if ($isNumeric) {
                        if (is_numeric($itemValuesArray[$iItemValue]) === FALSE) {
                            $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $itemValuesArray[$iItemValue].");
                        }
                    }
                }
            }
            $values = array_merge($values, $itemOccValues);
        } else {
            $insertValue = "";
            if (isset($curRecord[$itemName]["code"])) {
                $insertValue = $curRecord[$itemName]["code"];
                if ($isNumeric) {
                    if (is_numeric($insertValue) === FALSE) {
                        $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $insertValue. Setting it to blank");
                    }
                }
            }
            $values[] = $insertValue;
        }
    }

    private function formatDataRow($itemNamesMap, $values, $row): string {
        $itemNames = array_keys($itemNamesMap);
        $formatClass = "c2h_table_r" . $row % 2;
        $dataRowHtml = "<tr>";

        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $dataRowHtml .= "<td class=\"" . $formatClass . "\">";
            $dataRowHtml .= $this->getItemValueDisplayText($itemNamesMap[$itemNames[$iItem]], $values[$iItem]) . "</td>";
        }
        $dataRowHtml .= "</tr>";

        return $dataRowHtml;
    }

    private function getDisplayText($dictBase): string {
        return $dictBase->getName() . ": " . $dictBase->getLabel();
    }

    private function getItemValueDisplayText(Item $dictItem, $value): string {
        //TODO: getDisplayText for alpha
        $isNumeric = $dictItem->isNumeric();
        if ($isNumeric) {
            //get first vset
            if ((is_countable($dictItem->getValueSets()) ? count($dictItem->getValueSets()) : 0) > 0) {
                $dictValueSet = $dictItem->getValueSets()[0];

                $numValue = $dictItem->getDecimalPlaces() > 0 ? (float) $value : (int) $value;
                $label = $this->getValueLabelFromVset($dictValueSet, $numValue, $value);
                if (!empty($label) && trim($value) !== trim($label))
                    return empty($value) & $value !== 0 ? $label : $value . ": " . $label;
            }
        }
        return $value;
    }

    private function getValueLabelFromVset(ValueSet $dictValueSet, $value, $textValue): string {
        $this->logger->debug('printing valueSet: ' . print_r($dictValueSet, true));
        for ($iVal = 0; $iVal < (is_countable($dictValueSet->getValues()) ? count($dictValueSet->getValues()) : 0); $iVal++) {
            $dictValue = $dictValueSet->getValues()[$iVal];
            $pre80Dictionary = getType($dictValue) !== 'object' ? true : false;
            $label = $pre80Dictionary ? $dictValue["Label"][0] : $dictValue->getLabel();
            $isSpecial = false;
            if ($pre80Dictionary) {
                $isSpecial = isset($dictValue["Special"]);
            } else {
                $specialValue = $dictValue->getSpecial();
                $isSpecial = isset($specialValue);
            }
            //check if value is special$
            if ($isSpecial) {
                //specials do not have to value
                $vPair = $pre80Dictionary ? $dictValue["VPairs"][0] : $dictValue->getValuePairs()[0];
                if (getType($vPair) !== 'object') {
                    if ((trim($vPair === $value) || (trim($vPair) === trim($textValue))))
                        return $label;
                } elseif ((trim($vPair->getFrom()) === $value) || (trim($vPair->getFrom()) === trim($textValue))) {
                    return $label;
                }
            }
            if ($textValue === "")//text gets converted to numVal 0 do not process these. Only check for specials above.
                continue;
            //go through the vpairs
            $vPairCount = $pre80Dictionary ? (is_countable($dictValue["VPairs"]) ? count($dictValue["VPairs"]) : 0) :
                    (is_countable($dictValue->getValuePairs()) ? count($dictValue->getValuePairs()) : 0);
            for ($vPair = 0; $vPair < $vPairCount; $vPair++) {
                $dictVPair = $pre80Dictionary ? $dictValue["VPairs"][$vPair] : $dictValue->getValuePairs()[$vPair];
                if (getType($dictVPair) !== 'object') {
                    $this->logger->debug('printing vpair' . print_r($dictVPair, true));
                    continue;
                }
                $toVal = $dictVPair->getTo();
                $fromVal = $dictVPair->getFrom();

                if ($value == $fromVal) {
                    return $label;
                } elseif (isset($toVal)) {
                    if ($value > $fromVal && $value <= $toVal)
                        return $label;
                }
            }
        }
        return "";
    }

    private function getRecordItemsNameMap(Record $record, &$nameTypeMap) {

        $parentItem = null;
        for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
            $item = $record->getItems()[$iItem];
            if ($item->getItemType() === "Item") {
                $parentItem = $item;
                $item->setParentItem(null);
            } else {
                $item->setParentItem($parentItem);
            }
            $this->getRecordItemNameMap($item, $nameTypeMap);
        }
    }

    public function getRecordItemNameMap(Item $item, &$nameTypeMap) {
        $itemName = strtolower($item->getName());
        $itemOccurrences = $item->getItemSubitemOccurs();

        if ($itemOccurrences == 1) {
            $nameTypeMap[$itemName] = $item;
        } else {
            for ($occurrence = 1; $occurrence <= $itemOccurrences; $occurrence++) {
                $itemNameWithOccurrence = $itemName . '(' . $occurrence . ')';
                $nameTypeMap[$itemNameWithOccurrence] = $item;
            }
        }
    }
}
