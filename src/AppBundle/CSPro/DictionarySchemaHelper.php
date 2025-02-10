<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\CSPro;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\Dictionary\MySQLDictionarySchemaGenerator;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\Dictionary\Dictionary;
use AppBundle\CSPro\Dictionary\Record;
use Doctrine\DBAL\Schema;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\DataSettings;
use AppBundle\CSPro\Data\MySQLQuestionnaireSerializer;

/**
 * Description of DictionarySchemaHelper
 *
 * @author savy
 */
class DictionarySchemaHelper {

    public const JOB_STATUS_NOT_STARTED = '0';
    public const JOB_STATUS_IN_PROCESS = '1';
    public const JOB_STATUS_COMPLETE = '2';

    private $conn;
    private $config;
    private $dictionary;
    private $connectionParams;
    private $initialized;

    public function __construct(private string $dictionaryName, private PdoHelper $pdo, private LoggerInterface $logger) {
        $this->initialized = false;
        $this->dictionary = null;
        $this->connectionParams = null;
        $this->conn = null;
        $this->config = null;
    }

    private function getConnectionParameters(): bool {
        $stm = "SELECT host_name, schema_name, schema_user_name, AES_DECRYPT(schema_password, '" . "cspro') as `password` FROM `cspro_dictionaries_schema` JOIN `cspro_dictionaries` ON dictionary_id = cspro_dictionaries.id WHERE cspro_dictionaries.dictionary_name = :dictName";
        $bind = ['dictName' => $this->dictionaryName];

        $result = $this->pdo->fetchOne($stm, $bind);

        if ($result) {
            $this->connectionParams = ['dbname' => $result['schema_name'], 'user' => $result['schema_user_name'], 'password' => $result['password'], 'host' => $result['host_name'], 'driver' => 'pdo_mysql'];
            return true;
        } else {
            $this->connectionParams = null;
            $this->logger->info('Database information not found for dictionary: ' . $this->dictionaryName);
            return false;
        }
    }

    public static function updateProcessCasesOptions(Dictionary $dictionary, $processCasesOptions, LoggerInterface $logger) {
        //for each level 
        for ($iLevel = 0; $iLevel < (is_countable($dictionary->getLevels()) ? count($dictionary->getLevels()) : 0); $iLevel++) {
            $level = $dictionary->getLevels()[$iLevel];
            //level ids are always included they use the default value of true. No need to process
            for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $isRecordIncluded = self::isRecordIncluded($record, $processCasesOptions);
                $record->includeInBlobBreakOut($isRecordIncluded);
                $itemFromRecordIncluded = false;
                $recordName = $record->getName();
                $isRecordIncluded ? $logger->debug("Included Record $recordName") :$logger->debug("Excluded Item $recordName") ;
                for ($iItem = 0; $iItem < (is_countable($record->getItems()) ? count($record->getItems()) : 0); $iItem++) {
                    $item = $record->getItems()[$iItem];
                    $isItemIncluded = self::isItemIncluded($item, $processCasesOptions, $isRecordIncluded);
                    $item->includeInBlobBreakOut($isItemIncluded);
                    $itemName = $item->getName();
                    $isItemIncluded ? $logger->debug("Included Item $itemName") :$logger->debug("Excluded Item $itemName") ;
                    if($isItemIncluded){
                        $itemFromRecordIncluded = true;
                    }
                }
                //finally set the flag for the record to be included to true in the blob breakout if any item from record is included
                if($itemFromRecordIncluded){
                    $record->includeInBlobBreakOut($itemFromRecordIncluded);
                    $logger->debug("Included Record $recordName");
                }
            }
        }
    }

    public static function isRecordIncluded(Record $record, $processCasesOptions): bool {
        //record is included if it is included or any of the items are included
        $includeOptions = isset($processCasesOptions['include']) ? $processCasesOptions['include'] : null;
        $excludeOptions = isset($processCasesOptions['exclude']) ? $processCasesOptions['exclude'] : null;
        //if the include options are not set or is empty  or name is found the include list set included to true
        $name = $record->getName();
        $isRecordincluded = false;
        if (!isset($includeOptions) || (isset($includeOptions) && ((count($includeOptions) === 0) || (array_search(strtoupper($name), array_map('strtoupper', $includeOptions)) !== false)))) {
            $isRecordincluded = true;
        }
        //if the name if found in the excluded list set the included flag to false
        if (isset($excludeOptions) && count($excludeOptions) > 0 && array_search(strtoupper($name), array_map('strtoupper', $excludeOptions)) !== false) {
            $isRecordincluded = false;
        }
        return $isRecordincluded;
    }

    //if record is included in processing then call with parentIncluded to true, so that the item is also included by default
    public static function isItemIncluded($item, $processCasesOptions, $recordIncluded = false): bool {
        $included = $recordIncluded;
        $name = $item->getName();   
        $includeOptions = isset($processCasesOptions['include']) ? $processCasesOptions['include'] : null;
        $excludeOptions = isset($processCasesOptions['exclude']) ? $processCasesOptions['exclude'] : null;
        //if record is not included and include options is set then check if the item is included
        if (($included === false) && isset($includeOptions) && (count($includeOptions) > 0)) {
            $included = (array_search(strtoupper($name), array_map('strtoupper', $includeOptions)) !== false);
        }
        //if the name if found in the excluded list set the included flag to false
        if (isset($excludeOptions) && count($excludeOptions) > 0 && array_search(strtoupper($name), array_map('strtoupper', $excludeOptions)) !== false) {
            $included = false;
        }
        return $included;
    }

    public function initialize($checkDictionarySchema = false): bool {
//get the connection parameters
        /* Provide DBAL with some initial database infor */
        if ($this->initialized == true) { //allow init to be done only once to prevent gc
            return $this->initialized;
        }
        $this->config = new Configuration();
        try {
//load dictionary
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);
            $this->dictionary = $dictionaryHelper->loadDictionary($this->dictionaryName);

            /* Connect to the database */
            $this->initialized = $this->getConnectionParameters();
            if ($this->initialized == false) {
                return $this->initialized;
            }
            $this->conn = DriverManager::getConnection($this->connectionParams, $this->config);
            if ($checkDictionarySchema && !$this->IsValidSchema()) { //thread never should call using checkDictionarySchema as true
//drop all the tables that exist. 
                $this->cleanDictionarySchema();
                $processCasesOptions = $this->getProcessCaseOptions();
                $this->createDictionarySchema($processCasesOptions);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed initializing database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
        $this->initialized = true;
        return $this->initialized;
    }

    public function regenerateSchema(): bool {
        //get the connection parameters
        $this->config = new Configuration();
        try {
            // load dictionary
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);
            $this->dictionary = $dictionaryHelper->loadDictionary($this->dictionaryName);

            // connect to the database
            $this->initialized = $this->getConnectionParameters();
            if ($this->initialized == false) {
                return $this->initialized;
            }
            $this->conn = DriverManager::getConnection($this->connectionParams, $this->config);

            // drop all the tables that exist and recreate them
            $this->cleanDictionarySchema();
            $processCasesOptions = $this->getProcessCaseOptions();
            $this->createDictionarySchema($processCasesOptions);
        } catch (\Exception $e) {
            $strMsg = "Failed clearing database: " . $this->connectionParams['dbname'] . " for associated Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
        $this->initialized = true;
        return $this->initialized;
    }

    private function cleanDictionarySchema() {
        try {
            $tables = $this->conn->getSchemaManager()->listTables();
            if ((is_countable($tables) ? count($tables) : 0) > 0) {
                $this->conn->prepare("SET FOREIGN_KEY_CHECKS = 0;")->execute();

                foreach ($tables as $table) {
                    $sql = 'DROP TABLE ' . MySQLDictionarySchemaGenerator::quoteString($table->getName());
                    $this->conn->prepare($sql)->execute();
                }
                $this->conn->prepare("SET FOREIGN_KEY_CHECKS = 1;")->execute();
            }
        } catch (\Exception $e) {
            $strMsg = "Failed deleting tables from database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
    }

    private function createDictionarySchema($processCasesOptions) {

        $bind = [];
        try {
            $dictionarySchema = new MySQLDictionarySchemaGenerator($this->logger);
            $processCasesOptions = $this->getProcessCaseOptions();
            $schema = $dictionarySchema->generateDictionary($this->dictionary, $processCasesOptions);
            $dictionarySQL = $schema->toSql($this->conn->getDatabasePlatform());
            $dictionarySQL = implode(";" . PHP_EOL, $dictionarySQL);
            $this->logger->debug("writing schema SQL " . $dictionarySQL);

            $this->conn->prepare($dictionarySQL)->execute();

            //insert into cspro_meta dictionary information
            $dictionaryVersion = $this->dictionary->getVersion();
            $stm = "SELECT modified_time, `dictionary_full_content` FROM `cspro_dictionaries` "
                    . " WHERE  `dictionary_name` = '" . $this->dictionaryName . "'";
            $result = $this->pdo->fetchOne($stm);
            if ($result) {
                $stm = "INSERT INTO `cspro_meta`(`cspro_version`, `dictionary`, `source_modified_time`) "
                        . "VALUES (:version, :dictionary, :source_modified_time)";
                $bind['version'] = $dictionaryVersion;
                $bind['dictionary'] = $result['dictionary_full_content'];
                $bind['source_modified_time'] = $result['modified_time'];
                $stmt = $this->conn->executeUpdate($stm, $bind);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed generating tables in database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
    }

    public function getProcessCaseOptions(): array {
        $result = Array();
        $dataSettings = new DataSettings($this->pdo, $this->logger);
        $dataSetting = $dataSettings->getDataSetting($this->dictionary->getName(), false);
        $additionalConfig = isset($dataSetting['additionalConfig']) ? json_decode($dataSetting['additionalConfig'], true, 512, JSON_THROW_ON_ERROR) : null;
        if (isset($additionalConfig['processCasesOptions'])) {
            $result = $additionalConfig['processCasesOptions'];
        }
        return $result;
    }

    public function tableExists($table) {
        try {
            $result = $this->conn->executeQuery("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception) {
            return false;
        }
        // ALW - By default PDO will not throw exceptions, so check result also.
        return $result !== false;
    }

    public function IsValidSchema(): bool {
        $bind = [];
        //check the time stamp of dictionary in the meta table with the original dictionary timestamp.
        $isValid = false;
        try {
            if (!$this->tableExists("`cspro_meta`")) {
                return $isValid;
            }
            $stm = "SELECT source_modified_time FROM `cspro_meta` ";
            $stmt = $this->conn->executeQuery($stm);
            $result = $stmt->fetch();
            if ($result) {
                $stm = "SELECT count(*) FROM `cspro_dictionaries` "
                        . " WHERE  `dictionary_name` = :dictionaryName and `modified_time` = :source_modified_time";
                $bind['dictionaryName'] = $this->dictionaryName;
                $bind['source_modified_time'] = $result['source_modified_time'];

                $result = (int) $this->pdo->fetchValue($stm, $bind);
                $isValid = ($result === 1) ? true : false;
            }
        } catch (\Exception $e) {
            $strMsg = "Failed validating schema  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
        $this->logger->debug('The schema valid flag is ' . $isValid);
        return $isValid;
    }

    //reset in process jobs to not started at the start of the long process to be picked up again
    public function resetInProcesssJobs(): int {
        $bind = [];
        try {
            $stm = "UPDATE `cspro_jobs` SET `status`= :status WHERE `status` = :in_process_jobs";
            $bind['status'] = self::JOB_STATUS_NOT_STARTED;
            $bind['in_process_jobs'] = self::JOB_STATUS_IN_PROCESS;
            $count = $this->conn->executeUpdate($stm, $bind);
        } catch (\Exception $e) {
            $strMsg = "Failed resetting jobs in schema  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
        return $count;
    }

    public function processNextJob($maxCasesPerChunk): int {
        $bind = [];
        $jobId = 0;
//find a job that is not being processed and update its status to processing 
        try {
            $stm = "SELECT `id` FROM `cspro_jobs` "
                    . " WHERE  `status` = " . self::JOB_STATUS_NOT_STARTED . " ORDER BY `id`  LIMIT 1 ";
            $stmt = $this->conn->prepare($stm);
            $resultSet = $stmt->execute();
            $result = $resultSet->fetchAllAssociative();
            $jobId = (is_countable($result) ? count($result) : 0) > 0 ? $result[0]['id'] : $this->createJob($maxCasesPerChunk);
            if ($jobId) {
                $stm = "UPDATE `cspro_jobs` SET `status`= :status WHERE `id` = :id";
                $bind['status'] = self::JOB_STATUS_IN_PROCESS;
                $bind['id'] = $jobId;
                $this->conn->executeUpdate($stm, $bind);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed getting next job from database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
        return $jobId;
    }

    public function createJob($maxCasesPerChunk): int {
        $bind = [];
        //if a job already exists - get the endCaseId and endRevision if there are no cases at this revision 
//SELECT the most recent job and get the endCaseId and endRevision 
        $jobId = 0;
        $stm = "SELECT `id`, `start_caseid`, `start_revision`, `end_caseid`, `end_revision`, `cases_processed`, `status` FROM `cspro_jobs` "
                . "ORDER BY `id` DESC LIMIT 1 ";

        try {
            $stmt = $this->conn->prepare($stm);
            $resultSet = $stmt->execute();
            $result = $resultSet->fetchAllAssociative();
            $endRevision = 0;
            $endCaseId = 0;
            if ($result) {
                $endRevision = $result[0]['end_revision'];
                $endCaseId = $result[0]['end_caseid'];
            }
//select cases from the source cases  table  where revision = end_revision  end_revision id > end_caseid 
            $stm = "SELECT `id`, `revision` FROM " . $this->dictionaryName . " WHERE revision = :endRevision and `id` > :endCaseId  "
                    . " UNION "
                    . "SELECT `id`, `revision` FROM " . $this->dictionaryName . " WHERE revision > :endRevision "
                    . "ORDER BY `revision`, `id` LIMIT :limit";

            $limit = $maxCasesPerChunk;
            $stmt = $this->pdo->prepare($stm);
            $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindParam(':endCaseId', $endCaseId, \PDO::PARAM_INT);
            $stmt->bindParam(':endRevision', $endRevision, \PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetchAll();
            if ($result) {
                unset($bind);
                $bind['startCaseId'] = $result[0]['id'];
                $bind['startRevision'] = $result[0]['revision'];
                $bind['endCaseId'] = $result[count($result) - 1]['id'];
                $bind['endRevision'] = $result[count($result) - 1]['revision'];
                $bind['cases_to_process'] = count($result);
                $stm = "INSERT INTO `cspro_jobs`(`start_caseid`, `start_revision`, `end_caseid`, `end_revision` ,`cases_to_process`) "
                        . "VALUES (:startCaseId, :startRevision, :endCaseId, :endRevision, :cases_to_process)";
                $stmt = $this->conn->executeUpdate($stm, $bind);
                $jobId = $this->conn->lastInsertId();
            }
            return $jobId;
        } catch (\Exception $e) {
            $strMsg = "Failed creating job in database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
    }

    public function blobBreakOut($jobId, $processCasesOptions) {
        //$dictionary, PdoHelper $sourceDB, $targetDB, $jobID 
        //select cases from sourceDB and generate insertSQL to insert/update the case 
        try {
            $questionnaireSerializer = new MySQLQuestionnaireSerializer($this->dictionary, $jobId, $this->pdo, $this->conn, $this->logger);
            $questionnaireSerializer->serializeQuestionnaries($processCasesOptions);
        } catch (\Exception $e) {
            $strMsg = "Failed processing questionnaires for JobId: " . $jobId . " in database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw new \Exception($strMsg, 0, $e);
        }
    }

}
