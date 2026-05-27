<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\CSPro\Data;

use App\CSPro\Dictionary\Dictionary;
use App\CSPro\Dictionary\Level;
use App\CSPro\Dictionary\Record;
use App\CSPro\Dictionary\Item;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use Doctrine\DBAL\Connection;
use App\CSPro\Dictionary\MySQLDictionarySchemaGenerator;
use App\CSPro\DictionarySchemaHelper;
use Exception;

/**
 * Description of MySQLQuestionnaireSerializer
 *
 * @author savy
 */
class MySQLQuestionnaireSerializer {

    private $casesMap;  //target db connection
    private $casesIdMap;
    private $job;

    public function __construct(private Dictionary $dict, private $jobId, private PdoHelper $sourcePdo, private Connection $targetConnection, private LoggerInterface $logger) {
        $this->casesMap = [];
    }

    public function serializeQuestionnaries($processCasesOptions) {
        $bind = [];
        DictionarySchemaHelper::updateProcessCasesOptions($this->dict, $processCasesOptions, $this->logger);
        //        ini_set('memory_limit', '16G'); //increase memory if php memory limit is hit 
        $this->getJobInformation();
        $this->getQuestionnarieListToSerilaize();
        if (count($this->casesMap) == 0) {
            $this->logger->warning("No cases available to serialize for jobId: " . $this->jobId);
            return;
        }
        $strMsg = "Serializing " . count($this->casesMap) . " cases for dictionary : " . $this->dict->getName();
        $this->logger->info($strMsg);
        //delete questionnaires in a separate transaction to avoid deadlock issues
        try {
            $this->deleteQuestionnaires();
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed serializing cases';
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }

        //begin transaction 
        $this->targetConnection->beginTransaction();
        try {
            $caseCount = $this->serializeCases();
            $caseCount = $this->serializeQuestionnaireLevel();
            $this->serializeQuestionnaireRecords();
            $this->serializeNotes();

            //update job
            $jobId = $this->jobId;
            $stm = "UPDATE `cspro_jobs` SET `status`= :status, `cases_processed` = :totalCases WHERE `id` = :jobId";
            $bind['status'] = DictionarySchemaHelper::JOB_STATUS_COMPLETE;
            $bind['jobId'] = $this->jobId;
            $bind['totalCases'] = $caseCount;
            $this->targetConnection->executeUpdate($stm, $bind);
            //commit 
            $this->targetConnection->commit();
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed serializing cases';
            $this->logger->error($strMsg, ["context" => (string) $e]);
            $this->targetConnection->rollBack();
            throw new \Exception($strMsg, 0, $e);
        }
    }

    function includeCasesCreatedModifiedTime() {
        $include = false;
        $targetDatabaseName = $this->targetConnection->getDatabase();
        try {
            $query = "SELECT COUNT(*) as count  
                    FROM `information_schema`.`columns`
                    WHERE table_schema = '{$targetDatabaseName}'
                    AND table_name = 'cases'  AND column_name = 'created_time'";
            $result = $this->targetConnection->fetchOne($query);
            $include = $result > 0 ? true : $include;
        } catch (Exception $ex) {
            
        }
        return $include;
    }

    public function getJobInformation() {
        try {
            $stm = "SELECT `id`, `start_caseid`, `start_revision`, `end_caseid`, `end_revision`, `cases_to_process` FROM `cspro_jobs` "
                    . " WHERE  `id` = " . $this->jobId;
            $result = $this->targetConnection->fetchAllAssociative($stm);
            unset($this->job);
            if ($result) {
                $this->job = $result [0];
            }
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= "] Failed getting job information jobID: " . $this->jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

    //get list of questionnaires to process for the current job
    public function getQuestionnarieListToSerilaize() {
        //From the source get the list of cases
        try {
            // Select all the cases sent by the client that exist on the server
            $stm = 'SELECT  `id`, LCASE(CONCAT_WS("-", LEFT(HEX(uuid), 8), MID(HEX(uuid), 9,4), MID(HEX(uuid), 13,4), MID(HEX(uuid), 17,4), RIGHT(HEX(uuid), 12))) as uuid, questionnaire, `revision`,
                    `key`, `label`, `deleted`, `verified`, `modified_time`, `created_time`, `partial_save_mode`
			FROM ' . $this->dict->getName() . ' WHERE (`id` >= :startCaseId AND `revision` =  :startRevision) ';

            $stm .= " UNION " . 'SELECT  `id`, LCASE(CONCAT_WS("-", LEFT(HEX(uuid), 8), MID(HEX(uuid), 9,4), MID(HEX(uuid), 13,4), MID(HEX(uuid), 17,4), RIGHT(HEX(uuid), 12))) as uuid, questionnaire, `revision`,
                    `key`, `label`, `deleted`, `verified`, `modified_time`, `created_time`, `partial_save_mode`
			FROM ' . $this->dict->getName() . ' WHERE (`revision` >  :startRevision AND `revision` <= :endRevision) ';

            $stm .= ' ORDER BY  `revision`, `id`  LIMIT :limit; ';
            $stmt = $this->sourcePdo->prepare($stm);

            $stmt->bindParam(':limit', $this->job['cases_to_process'], \PDO::PARAM_INT);
            $stmt->bindParam(':startCaseId', $this->job['start_caseid']);
            $stmt->bindParam(':startRevision', $this->job['start_revision']);
            $stmt->bindParam(':endRevision', $this->job['end_revision']);

//            $this->logger->debug($stmt->queryString);
            $stmt->execute();
            $result = $stmt->fetchAll();

            $this->casesMap = [];
            foreach ($result as &$row) {
                $row['questionnaire'] = gzuncompress(substr($row['questionnaire'], 4));
                $this->casesMap[$row ['uuid']] = $row;
            }
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed getting cases to process for dictionary ';
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

    //cascade delete exisiting questionnaires before breaking out JSON
    public function deleteQuestionnaires() {
        //delete all the questionnaires that match 
        $caseList = array_keys($this->casesMap);
        $strCaseList = "'" . implode("','", $caseList) . "'";

        $this->targetConnection->beginTransaction();
        try {
            //delete existing cases
            $stm = 'DELETE FROM `cases` WHERE `id` in ( ' . $strCaseList . ")";
            $count = $this->targetConnection->executeUpdate($stm);

            //delete notes for these cases
            $stm = 'DELETE FROM `notes` WHERE `case_id` in ( ' . $strCaseList . ")";
            $this->targetConnection->executeUpdate($stm);

            //cascade delete cases from break out tables
            $stm = 'DELETE FROM `level-1` WHERE `case-id` in ( ' . $strCaseList . ")";
            $count = $this->targetConnection->executeUpdate($stm);
            $this->logger->debug("Deleted $count cases");

            $this->targetConnection->commit();
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed deleting cases';
            $this->logger->error($strMsg, ["context" => (string) $e]);
            $this->targetConnection->rollBack();
            throw new \Exception($strMsg, 0, $e);
        }
    }

    private function generateLevelInsertStatement(&$nameTypeMap): string {
        $stm = "INSERT INTO `level-1` (";
        //TODO: fix for multiple levels
        $iLevel = 0;
        $level = $this->dict->getLevels()[$iLevel];

        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $this->getRecordItemNameType($level->getIdItems()[$iItem], $nameTypeMap);
        }
        $keys = array_keys($nameTypeMap);
        $quotedItemNames = [];
        foreach ($keys as $key) {
            $quotedItemNames[] = MySQLDictionarySchemaGenerator::quoteString($key);
        }
        $itemList = implode(",", $quotedItemNames);
        $itemList = "`case-id`," . $itemList;

        $stm .= $itemList . ") VALUES ";
        return $stm;
    }

    private function generateRecordInsertStatement(Record $record, &$nameTypeMap): string {
        $recordName = MySQLDictionarySchemaGenerator::quoteString(strtolower($record->getName()));
        $stm = "INSERT INTO $recordName (";

        $this->getRecordItemsNameType($record, $nameTypeMap);
        $keys = array_keys($nameTypeMap);
        $quotedItemNames = [];
        foreach ($keys as $key) {
            $quotedItemNames[] = MySQLDictionarySchemaGenerator::quoteString($key);
        }

        $itemList = implode(",", $quotedItemNames);

        $parentLevelName = "level-" . (string) ($record->getLevel()->getLevelNumber() + 1);
        $parentId = $parentLevelName . "-id";

        if ($record->getMaxRecords() > 1) {
            $itemList = "`$parentId`, `occ`, " . $itemList;
        } else {
            $itemList = "`$parentId`," . $itemList;
        }
        $itemList = rtrim($itemList, ",");
        $stm .= $itemList . ") VALUES ";

        return $stm;
    }

    private function getRecordItemsNameType(Record $record, &$nameTypeMap) {
        $parentItem = null;
        for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
            $item = $record->getItems()[$iItem];
            if ($item->getItemType() === "Item") {
                $parentItem = $item;
                $item->setParentItem(null);
            } else {
                $item->setParentItem($parentItem);
            }
            if ($item->isIncludedInBlobBreakOut()) {
                $this->getRecordItemNameType($item, $nameTypeMap);
            }
        }
    }

    public function getRecordItemNameType(Item $item, &$nameTypeMap) {

        $itemName = strtolower($item->getName());
        $itemType = MySQLDictionarySchemaGenerator::generateColumnType($item);
        $itemOccurrences = $item->getItemSubitemOccurs();

        if ($itemOccurrences == 1) {
            $nameTypeMap[$itemName] = $itemType;
        } else {
            for ($occurrence = 1; $occurrence <= $itemOccurrences; $occurrence++) {
                $itemNameWithOccurrence = $itemName . '(' . $occurrence . ')';
                $nameTypeMap[$itemNameWithOccurrence] = $itemType;
            }
        }
    }

    public function serializeQuestionnaireLevel(): int {
        $caseList = array_keys($this->casesMap);
        $nameTypeMap = [];
        $stm = $this->generateLevelInsertStatement($nameTypeMap);
        $idItemNames = array_keys($nameTypeMap);
        $idItemNames = array_map('strtoupper', $idItemNames);
        $values = [];
        $singlePlaceholder = '(' . implode(', ', array_fill(0, count($idItemNames) + 1, '?')) . ')';
        // (?, ?), ... , (?, ?)
        $placeholders = implode(', ', array_fill(0, count($this->casesMap), $singlePlaceholder));

        $this->logger->debug('serializeQuestionnaireLevel: processing ' . count($this->casesMap) . ' cases to insert');
        $level = $this->dict->getLevels()[0];
        $levelName = strtoupper($level->getName());
        foreach ($caseList as $case) {
            $caseJsonArray = $this->casesMap[$case];
            $values[] = $case; //case-id
            foreach ($idItemNames as $idItem) {
                if (isset($caseJsonArray[$levelName][$idItem]["code"])) {
                    $values[] = $caseJsonArray[$levelName][$idItem]["code"];
                } else {
                    $values[] = null;
                }
            }
        }

        $stm .= $placeholders;
        try {
            $count = $this->targetConnection->executeUpdate($stm, $values);
            $this->logger->debug("inserted  $count rows into case level");
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= "] Failed writing cases level information to database for  jobID: " . $this->jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
        return $count;
    }

    public function serializeCases(): int {
        $caseList = array_keys($this->casesMap);
        if ($this->includeCasesCreatedModifiedTime()) {
            $stm = "INSERT INTO `cases` (`id`, `key`, `label`, `last_modified_revision`, `deleted`, `verified`, `modified_time`, `created_time`, `partial_save_mode`) VALUES ";
            $itemNames = ["uuid", "key", "label", "revision", "deleted", "verified", "modified_time", "created_time", "partial_save_mode"];
        } else {
            $stm = "INSERT INTO `cases` (`id`, `key`, `label`, `last_modified_revision`, `deleted`, `verified`, `partial_save_mode`) VALUES  ";
            $itemNames = ["uuid", "key", "label", "revision", "deleted", "verified", "partial_save_mode"];
        }
        $values = [];
        $singlePlaceholder = '(' . implode(', ', array_fill(0, count($itemNames), '?')) . ')';
        // (?, ?), ... , (?, ?)
        $placeholders = implode(', ', array_fill(0, count($this->casesMap), $singlePlaceholder));

        $this->logger->debug('Inserting into cases table: processing ' . count($this->casesMap) . ' cases to insert');
        foreach ($caseList as $case) {
            $caseRow = $this->casesMap[$case];
            foreach ($itemNames as $itemName) {
                if (isset($caseRow[$itemName])) {
                    $values[] = $caseRow[$itemName];
                } else {
                    $values[] = null;
                }
            }
            //once the case is processed change the key in the map to point to json decoded questionnaire for the 
            //rest of the tables to be broken out
            $this->casesMap[$case] = json_decode($caseRow['questionnaire'], true);
            if (json_last_error() != JSON_ERROR_NONE) {
                $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
                $strMsg .= ' Dictionary: ' . $this->dict->getName() . "] Failed writing cases to database for  jobID: " . $this->jobId;
                $strQuestionnaire = ' Case: ' . $case . ' Questionnaire: ' . $caseRow['questionnaire'];
                $this->logger->error($strMsg . $strQuestionnaire . " Error decoding json questionnaire. " . json_last_error_msg());
                throw new \Exception($strMsg);
            }
        }

        $stm .= $placeholders;
        try {
            $count = $this->targetConnection->executeUpdate($stm, $values);
            $this->logger->debug("inserted  $count rows into cases table");
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= "] Failed writing cases to database for  jobID: " . $this->jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
        return $count;
    }

    public function serializeNotes(): int {
        $caseList = array_keys($this->casesMap);
        $result = [];
        foreach ($caseList as $case) {
            $case = $this->casesMap[$case];
            if (isset($case["notes"])) {
                foreach ($case["notes"] as $note) {
                    $row['case_id'] = $case['uuid'];
                    $row['field_name'] = $note['name'] ?? "";
                    $default = $this->dict->getName() ===  $row['field_name'] ? 0 : 1; //case note has no occurrences set
                    $row['level_key'] = $note['levelKey'] ?? null;
                    $row['record_occurrence'] = $note['occurrences']['record'] ?? $default;
                    $row['item_occurrence'] = $note['occurrences']['item'] ?? $default;
                    $row['subitem_occurrence'] = $note['occurrences']['subitem'] ?? 0;
                    $row['operator_id'] = $note['operatorId'] ?? null;

                    $rawTime = $note['modifiedTime'] ?? null;
                    $timestamp = $rawTime ? strtotime($rawTime) : false;
                    $row['modified_time'] = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;

                    $row['content'] = $note['text'] ?? "";
                    $result[] = $row;
                }
            }
        }
        try {
            if ((is_countable($result) ? count($result) : 0) == 0)
                return 0;

            //add the notes for these cases to  the notes table 
            $stm = "INSERT INTO `notes` (`case_id`, `field_name`, `level_key`, `record_occurrence`, `item_occurrence`, "
                    . "`subitem_occurrence`, `content`, `operator_id`, `modified_time`) VALUES ";
            $itemNames = ["case_id", "field_name", "level_key", "record_occurrence", "item_occurrence", "subitem_occurrence", "content", "operator_id", "modified_time"];
            $values = [];
            $singlePlaceholder = '(' . implode(', ', array_fill(0, count($itemNames), '?')) . ')';
            // (?, ?), ... , (?, ?)
            $placeholders = implode(', ', array_fill(0, is_countable($result) ? count($result) : 0, $singlePlaceholder));

            $this->logger->debug('Inserting into notes table');
            foreach ($result as $row) {
                foreach ($itemNames as $itemName) {
                    $values[] = $row[$itemName] ?? null;
                }
            }

            $stm .= $placeholders;
            $count = $this->targetConnection->executeUpdate($stm, $values);
            $this->logger->debug("inserted  $count notes");
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= "] Failed writing case notes to database for  jobID: " . $this->jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
        return $count;
    }

    private function getCaseIdsMap() {
        try {
            // Select all the cases sent by the client that exist on the server
            $stm = 'SELECT  `level-1-id` as id, `case-id` as uuid FROM `level-1` WHERE `case-id` in (';
            $strOrderBy = ' ORDER BY  id';

            $strCaseList = "'" . implode("','", array_keys($this->casesMap)) . "'";
            $stm = $stm . $strCaseList . ")" . $strOrderBy;
            $result = $this->targetConnection->fetchAllAssociative($stm);

            $this->casesIdMap = [];
            foreach ($result as $row) {
                $this->casesIdMap[$row ['uuid']] = $row['id'];
            }
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed getting cases to process for dictionary';
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

    public function serializeQuestionnaireRecords() {
        $iLevel = 0;
        $level = $this->dict->getLevels()[$iLevel];
        $this->getCaseIdsMap();
        try {
            for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $record->setLevel($level);
                if ($record->isIncludedInBlobBreakOut()) {
                    $this->logger->debug('serializing record ' . $record->getName());
                    $this->serializeRecord($record);
                }
            }
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= '] Failed writing case records to database for jobID: ' . $this->jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

    public function fillItemValues(Item $item, Record $record, $curRecord, &$values) {
        $occurs = $item->getItemSubitemOccurs();
        $itemName = strtoupper($item->getName());
        $isNumeric = $item->isNumeric();

        if ($occurs > 1) {
            $itemOccValues = array_fill(0, $occurs, null);
            if (isset($curRecord[$itemName])) {
                $itemValuesArray = $curRecord[$itemName];
                for ($iItemValue = 0; $iItemValue < (is_countable($itemValuesArray) ? count($itemValuesArray) : 0); $iItemValue++) {
                    $itemOccValues[$iItemValue] = $itemValuesArray[$iItemValue]["code"]??null;
                    if ($isNumeric && isset($itemOccValues[$iItemValue])) {
                        if (is_numeric($itemOccValues[$iItemValue]) === FALSE) {
                            $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $itemValuesArray[$iItemValue]. Setting it to null");
                            $itemOccValues[$iItemValue] = null;
                        }
                    }
                }
            }
            $values = array_merge($values, $itemOccValues);
        } else {
            $insertValue = null;
            if (isset($curRecord[$itemName]["code"])) {
                $insertValue = $curRecord[$itemName]["code"];
                if ($isNumeric) {
                    if (is_numeric($insertValue) === FALSE) {
                        $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $insertValue. Setting it to null");
                        $insertValue = null;
                    }
                }
            }
            $values[] = $insertValue;
        }
    }

    public function serializeRecord(Record $record) {
        $caseList = array_keys($this->casesMap);
        $nameTypeMap = [];
        $stm = $this->generateRecordInsertStatement($record, $nameTypeMap);
        $recordItemNames = array_keys($nameTypeMap);
        $recordItemNames = array_map('strtoupper', $recordItemNames);
        $values = [];
        //add +1 for level-1-id
        if ($record->getMaxRecords() > 1) {//to account for level id and occ 
            $singlePlaceholder = '(' . implode(', ', array_fill(0, count($recordItemNames) + 2, '?')) . ')';
        } else {//to account for level-id
            $singlePlaceholder = '(' . implode(', ', array_fill(0, count($recordItemNames) + 1, '?')) . ')';
        }
        // (?, ?), ... , (?, ?)

        $recordCount = 0;
        $level = $this->dict->getLevels()[0];
        $levelName = strtoupper($level->getName());
        //get the hashmap of caseIds and their new ids to insert into the records id as foreign key
        foreach ($caseList as $case) {
            $caseJsonArray = $this->casesMap[$case][$levelName];
            $newCaseId = $this->casesIdMap[$case];
            unset($recordList);
            if (isset($caseJsonArray[$record->getName()])) {
                $recordList = $caseJsonArray[$record->getName()];
                foreach ($recordList as $curRec) {
                    $recordCount++;
                    $values[] = $newCaseId;
                    if ($record->getMaxRecords() > 1) {
                        $values[] = $recordCount;
                    }

                    $parentItem = null;
                    for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
                        $item = $record->getItems()[$iItem];
                        if ($item->getItemType() === "Item") {
                            $parentItem = $item;
                            $item->setParentItem(null);
                        } else {
                            $item->setParentItem($parentItem);
                        }
                        if ($item->isIncludedInBlobBreakOut()) {
                            $this->fillItemValues($item, $record, $curRec, $values);
                        }
                    }
                }
            }
        }

        $placeholders = implode(', ', array_fill(0, $recordCount, $singlePlaceholder));

        $stm .= $placeholders;
        if ($recordCount == 0) {
            $this->logger->debug("No records to output " . $record->getName());
            return;
        }
        try {
            $count = $this->targetConnection->executeUpdate($stm, $values);
            $this->logger->debug("inserted  $count records");
        } catch (\Exception $e) {
            $strMsg = '[SourceDB: ' . $this->sourcePdo->getDsn() . ' TargetDB: ' . $this->targetConnection->getDatabase();
            $strMsg .= ' Dictionary: ' . $this->dict->getName();
            $strMsg .= "] Failed writing case records to database for  record: " . $record->getName();
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

}
