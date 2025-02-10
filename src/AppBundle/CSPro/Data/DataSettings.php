<?php

namespace AppBundle\CSPro\Data;

use AppBundle\CSPro\DictionarySchemaHelper;
use AppBundle\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DBALException;

class DataSettings {

    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger)
    {
    }

    public function getDataSettings() {
        $dataSettings = $this->pdo->query('SELECT `cspro_dictionaries`.`id` as id, `dictionary_name` as name, `dictionary_label` as label,  `host_name` as targetHostName, `schema_name` as targetSchemaName,'
                        . ' `schema_user_name` as dbUserName, AES_DECRYPT(`schema_password`, \'cspro\') as dbPassword, `additional_config` as additionalConfig, `map_info` as mapInfo FROM `cspro_dictionaries_schema` RIGHT JOIN cspro_dictionaries'
                        . '  ON dictionary_id = cspro_dictionaries.id    ORDER BY dictionary_label')->fetchAll();
        $this->getDataCounts($dataSettings);

        //clear password field
        foreach ($dataSettings as &$dataSetting) {
            $dataSetting['dbPassword'] = "";
        }
        return $dataSettings;
    }

    public function getDataSetting($dictionaryName, $clearPassWord) {
        $bind = [];
        $dataSetting = null;
        try {
            $stm = 'SELECT `cspro_dictionaries`.`id` as id, `dictionary_name`as name, dictionary_label as label,  `host_name` as targetHostName, `schema_name` as targetSchemaName,'
                    . ' `schema_user_name` as dbUserName, AES_DECRYPT(`schema_password`, \'cspro\') as dbPassword, `additional_config` as additionalConfig, `map_info` as mapInfo FROM `cspro_dictionaries_schema` RIGHT JOIN cspro_dictionaries'
                    . '  ON dictionary_id = cspro_dictionaries.id  WHERE dictionary_name = :dictName';

            $bind['dictName'] = $dictionaryName;
            $dataSetting = $this->pdo->fetchOne($stm, $bind);
            //clear password field
            if ($clearPassWord && isset($dataSetting)) {
                $dataSetting['dbPassword'] = "";
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting data settings: ' . $dictionaryName, ["context" => (string) $e]);
            throw $e;
        }
        return $dataSetting;
    }

    public function addDataSetting($dataSetting): bool {
        $bind = [];
        $sourceDBName = $this->pdo->query('select database()')->fetchColumn();
        $dataSetting['targetSchemaName'] = trim($dataSetting['targetSchemaName']);
        $dataSetting['dbPassword'] = trim($dataSetting['dbPassword']);
        if (strcasecmp($sourceDBName, $dataSetting['targetSchemaName']) == 0) {
            throw new \Exception("Source database: $sourceDBName cannot be same as  Target database: " . $dataSetting['targetSchemaName']);
        }
        $connectionParams = ['dbname' => $dataSetting['targetSchemaName'], 'user' => $dataSetting['dbUserName'], 'password' => $dataSetting['dbPassword'], 'host' => $dataSetting['targetHostName'], 'driver' => 'pdo_mysql'];
        $config = new Configuration();
        try {
            $conn = DriverManager::getConnection($connectionParams, $config);
            $isConnected = $conn->connect();
//if connection successful add
            if ($isConnected) {
                $stm = "INSERT INTO `cspro_dictionaries_schema`(`dictionary_id`, `host_name`, `schema_name`, `schema_user_name`, `schema_password`, `additional_config`, `map_info`) "
                        . "VALUES (:id, :targetHostName, :targetSchemaName, :dbUserName,AES_ENCRYPT(:dbPassword, :keyString), :additionalConfig, :mapInfo)";

                $bind['id'] = $dataSetting['id'];
                $bind['targetHostName'] = $dataSetting['targetHostName'];
                $bind['targetSchemaName'] = $dataSetting['targetSchemaName'];
                $bind['dbUserName'] = $dataSetting['dbUserName'];
                $bind['dbPassword'] = $dataSetting['dbPassword'];
                $bind['additionalConfig'] = json_encode($dataSetting['additionalConfig'], JSON_THROW_ON_ERROR);
                $bind['mapInfo'] = json_encode($dataSetting['mapInfo'], JSON_THROW_ON_ERROR);
                $bind['keyString'] = 'cspro';
                $stmt = $this->pdo->prepare($stm);
                $stmt->execute($bind);
                $this->updateDictionarySchema($dataSetting['id']);
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed adding configuration: " . $e->getMessage());
            throw $e;
        }
        $flag = isset($conn) ? true : false;
        return $flag;
    }

    public function updateDataSetting($dataSetting): bool {
        $bind = [];
        $sourceDBName = $this->pdo->query('select database()')->fetchColumn();
        $dataSetting['targetSchemaName'] = trim($dataSetting['targetSchemaName']);
        $dataSetting['dbPassword'] = trim($dataSetting['dbPassword']);
        if (strcasecmp($sourceDBName, $dataSetting['targetSchemaName']) == 0) {
            throw new \Exception("Source database: $sourceDBName cannot be same as  Target database: " . $dataSetting['targetSchemaName']);
        }
        $this->logger->debug('setting is ' . print_r($dataSetting,true));
        $connectionParams = ['dbname' => $dataSetting['targetSchemaName'], 'user' => $dataSetting['dbUserName'], 'password' => $dataSetting['dbPassword'], 'host' => $dataSetting['targetHostName'], 'driver' => 'pdo_mysql'];
        $config = new Configuration();
        try {
            $conn = DriverManager::getConnection($connectionParams, $config);
            $isConnected = $conn->connect();
//if connection successful add
            if ($isConnected) {
                $hasProcessCasesUpdateOccurred = $this->hasProcessCasesOptionsUpdated($dataSetting);
              
                $stm = "UPDATE `cspro_dictionaries_schema` SET `host_name` =  :targetHostName, `schema_name` =  :targetSchemaName,"
                        . " `schema_user_name` = :dbUserName, `schema_password` = AES_ENCRYPT(:dbPassword, :keyString), "
                        . " `additional_config` = :additionalConfig, `map_info` = :mapInfo"
                        . " WHERE `dictionary_id` = :id";

                $bind['id'] = $dataSetting['id'];
                $bind['targetHostName'] = $dataSetting['targetHostName'];
                $bind['targetSchemaName'] = $dataSetting['targetSchemaName'];
                $bind['dbUserName'] = $dataSetting['dbUserName'];
                $bind['dbPassword'] = $dataSetting['dbPassword'];
                $bind['additionalConfig'] = json_encode($dataSetting['additionalConfig'], JSON_THROW_ON_ERROR);
                $bind['mapInfo'] = json_encode($dataSetting['mapInfo'], JSON_THROW_ON_ERROR);
                $bind['keyString'] = 'cspro';
                $stmt = $this->pdo->prepare($stm);
                $stmt->execute($bind);
                
                if ($hasProcessCasesUpdateOccurred) {
                    $this->updateDictionarySchema($dataSetting['id']);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed updating configuration: " . $e->getMessage());
            throw $e;
        }
        $flag = isset($conn) ? true : false;
        return $flag;
    }

    public function getDataCounts(&$dataSettings) {
//get each dictionary get the counts in the source and target schema
        foreach ($dataSettings as &$dataSetting) {
            $dataSetting['totalCases'] = "";
            $dataSetting['processedCases'] = "";
            $dataSetting['lastProcessedTime'] = "";

            if (isset($dataSetting['targetSchemaName'])) {
                $stm = "SELECT count(*) FROM `" . $dataSetting['name'] . "` WHERE `deleted` = 0";
                $caseCount = (int) $this->pdo->fetchValue($stm);
                $dataSetting['totalCases'] = $caseCount;

//get number of cases processsed.
                $connectionParams = ['dbname' => $dataSetting['targetSchemaName'], 'user' => $dataSetting['dbUserName'], 'password' => $dataSetting['dbPassword'], 'host' => $dataSetting['targetHostName'], 'driver' => 'pdo_mysql'];
                $config = new Configuration();
                try {
                    $conn = DriverManager::getConnection($connectionParams, $config);

//get processsed case count 
                    $dataSetting['processedCases'] = 0;
                    $statement = $conn->executeQuery('SELECT count(*) FROM `cases` where `deleted`=0');
                    $processedCases = $statement->fetchOne();
                    $dataSetting['processedCases'] = $processedCases;

//get processed time (modified time) from the most recently processed job
                    $statement = $conn->executeQuery('SELECT id , modified_time FROM `cspro_jobs` WHERE id = (SELECT max(id) from cspro_jobs where status =2)');
                    if (($row = $statement->fetchAssociative()) !== false) {
                        $dataSetting['lastProcessedTime'] = $row['modified_time'];
                    }
                } catch (\Exception $e) {
                    if (strpos((string) $e, 'SQLSTATE[42S02]') == FALSE) {
                        $this->logger->error('Failed getting case counts and last processed time', ["context" => (string) $e]);
                    }
                }
            }
        }
        return $dataSettings;
    }
    
    function hasProcessCasesOptionsUpdated($dataSetting): bool {
        try {
            $dictionaryId = $dataSetting['id'];
            $stm = 'SELECT `additional_config` FROM `cspro_dictionaries_schema` WHERE `dictionary_id` = :id';
            $bind = [];
            $bind['id'] = $dictionaryId;
            $currentAdditionalConfig = $this->pdo->fetchValue($stm, $bind);
            $currentProcessCasesOptions = "";
            if ($currentAdditionalConfig !== "null") {
                $currentAdditionalConfig = json_decode($currentAdditionalConfig, true);
                // todo: the key 'processCasesOptions' will exist. otherwise, the upload of the additional configuration options will be rejected by the json validation.
                $currentProcessCasesOptions = $currentAdditionalConfig['processCasesOptions'];
                $currentProcessCasesOptions = json_encode($currentProcessCasesOptions);
            }

            $newProcessCasesOptions = "";
            if (isset($dataSetting['additionalConfig'])) {
                $newAdditionalConfig = $dataSetting['additionalConfig'];
                // todo: the key 'processCasesOptions' will exist. otherwise, the upload of the additional configuration options will be rejected by the json validation.
                $newProcessCasesOptions = $newAdditionalConfig['processCasesOptions'];
                $newProcessCasesOptions = json_encode($newProcessCasesOptions);
            }

            if ($currentProcessCasesOptions === $newProcessCasesOptions) {
                // they're the same. nothing to do.
                return false;
            }
            else {
                // they're different. the data settings scheme will need to be recreated.
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare current and old process cases options. Dictionary Id: ' . $dictionaryId, ["context" => (string) $e]);
            throw $e;    
        }
    }
    
    function updateDictionarySchema($dictionaryId) {
        try {
            // get dictionary name
            $stm = 'SELECT `dictionary_name` FROM `cspro_dictionaries` WHERE `id` = :id';
            $bind = [];
            $bind['id'] = $dictionaryId;
            $dictName = $this->pdo->fetchValue($stm, $bind);

            // drop tables and recreate them. this will delete data (processed cases).
            $dictionarySchemaHelper = new DictionarySchemaHelper($dictName, $this->pdo, $this->logger);
            $dictionarySchemaHelper->regenerateSchema();
        } catch (\Exception $e) {
            $this->logger->error('Failed recreating data setting schema. Dictionary Id: ' . $dictionaryId, ["context" => (string) $e]);
            throw $e;
        }
    }
    
    function deleteDataSetting($dictionaryId): bool {
        try {
            // get dictionary name
            $stm = 'SELECT `dictionary_name` FROM `cspro_dictionaries` WHERE `id` = :id';
            $bind = [];
            $bind['id'] = $dictionaryId;
            $dictName = $this->pdo->fetchValue($stm, $bind);
            
            // drop tables and recreate them. this will delete data (processed cases).
            $dictionarySchemaHelper = new DictionarySchemaHelper($dictName, $this->pdo, $this->logger);
            $dictionarySchemaHelper->regenerateSchema();
            
            $stm = 'DELETE FROM `cspro_dictionaries_schema` WHERE `dictionary_id` = :id';
            $row_count = $this->pdo->fetchAffected($stm, $bind);
            return $row_count;
        } catch (\Exception $e) {
            $this->logger->error('Failed deleting configuration. Dictionary Id: ' . $dictionaryId, ["context" => (string) $e]);
            throw $e;
        }
    }

}
