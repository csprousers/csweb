<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\Dictionary;
use AppBundle\CSPro\Dictionary\Level;
use AppBundle\CSPro\Dictionary\Record;
use AppBundle\CSPro\Dictionary\Item;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\DictionarySchemaHelper;

/**
 * Description of MySQLDictionarySchemaGenerator
 *
 * @author savy
 */
class MySQLDictionarySchemaGenerator {

    public const COLUMN_TYPE_INTEGER = 'integer';
    public const COLUMN_TYPE_DECIMAL = 'decimal';
    public const COLUMN_TYPE_TEXT = 'text';

    private $schema;

    public function __construct(private LoggerInterface $logger) {
        $this->schema = null;
    }

    public static function generateColumnType(Item $item): string {
        if ($item->getDataType() === "Numeric") {
            return self::COLUMN_TYPE_DECIMAL;
        } else {
            return self::COLUMN_TYPE_TEXT;
        }
    }

    public function generateDictionary(Dictionary $dictionary, $processCasesOptions) {

        DictionarySchemaHelper::updateProcessCasesOptions($dictionary, $processCasesOptions, $this->logger);
        $this->schema = new Schema();
        $this->createDefaultTables();
        $parentLevel = null;
        //TODO check for Charset | Collation | comment
        for ($iLevel = 0; $iLevel < (is_countable($dictionary->getLevels()) ? count($dictionary->getLevels()) : 0); $iLevel++) {
            $level = $dictionary->getLevels()[$iLevel];
            $level->setLevelNumber($iLevel);
            $this->generateLevel($level, $parentLevel);
            $parentLevel = $dictionary->getLevels()[$iLevel];
        }
        return $this->schema;
    }

    public function generateLevel(Level $level, ?Level $parentLevel) {
        //for each record generate sql 
        $this->generateLevelIdsTable($level, $parentLevel);

        for ($iRecord = 0; $iRecord < (is_countable($level->getRecords()) ? count($level->getRecords()) : 0); $iRecord++) {
            $record = $level->getRecords()[$iRecord];
            $record->setLevel($level);
            if ($record->isIncludedInBlobBreakOut()) {
                $this->generateRecordTable($record);
            }
        }
    }

    public function generateLevelIdsTable(Level $level, ?Level $parentLevel) {

        $levelName = "level-" . (string) ($level->getLevelNumber() + 1);
        $parentLevelName = $parentLevel ? "level-" . $parentLevel->getLevelNumber() : "case";
        $parentId = $parentLevelName . "-id";

        //create a table using DBAL 
        $levelIdTable = $this->schema->createTable(static::quoteString($levelName));
        //add columns 
        $autoIncrementFlag = $parentLevel ? false : true;
        $levelIdTable->addColumn(static::quoteString($levelName) . "-id", "integer", ["unsigned" => true, "notnull" => true, "autoincrement" => $autoIncrementFlag]);
        // First level is linked to uuid in cases table, others are linked by integer to parent level
        $parentKeyType = $parentLevel ? self::COLUMN_TYPE_INTEGER : self::COLUMN_TYPE_TEXT;
        $unsignedFlag = $parentKeyType === self::COLUMN_TYPE_INTEGER ? true : false;
        $levelIdTable->addColumn(static::quoteString($parentId), $parentKeyType, ["unsigned" => $unsignedFlag, "notnull" => true]);

        // For second and third level nodes need an order column
        if ($level->getLevelNumber() > 0) {
            $levelIdTable->addColumn(static::quoteString("occ"), "integer", ["unsigned" => true, "notnull" => true, "default" => 1]);
        }
        //set primary key on id 
        $levelIdTable->setPrimaryKey([static::quoteString($levelName . "-id")]);

        //add id items to the levelId table 
        for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
            $this->addRecordItemToTable($levelIdTable, $level->getIdItems()[$iItem]);
        }

        if ($parentLevel) {
            $levelIdTable->addForeignKeyConstraint(static::quoteString($parentLevelName), [static::quoteString($parentId)], [static::quoteString($parentId)], ["onDelete" => "CASCADE"]);
        }
        // Only first level parent id is unique since that is case.id, at higher levels can have multiple child nodes
        $unique = $parentLevel == null ? true : false;

        if ($unique) {
            if ($parentKeyType === self::COLUMN_TYPE_TEXT) {
                $levelIdTable->addUniqueIndex([static::quoteString($parentId)], null, ["lengths" => [191]]);
            } else {
                $levelIdTable->addUniqueIndex([static::quoteString($parentId)]);
            }
        }
    }

    public function generateRecordTable(Record $record) {
        $parentLevelName = "level-" . (string) ($record->getLevel()->getLevelNumber() + 1);
        $parentId = $parentLevelName . "-id";

        //create a table using DBAL 
        $recordTable = $this->schema->createTable(strtolower($record->getName()));
        //add columns -added auto increment for MySQL for record-ids 
        $recordTable->addColumn(static::quoteString(strtolower($record->getName()) . "-id"), "integer", ["unsigned" => true, "notnull" => true, "autoincrement" => true]);
        //set primary key on id 
        $recordTable->setPrimaryKey([static::quoteString(strtolower($record->getName()) . "-id")]);

        $recordTable->addColumn(static::quoteString($parentId), "integer", ["unsigned" => true, "notnull" => true]);

        //add occ column if max occs > 1 
        if ($record->getMaxRecords() > 1) {
            $recordTable->addColumn(static::quoteString("occ"), "integer", ["unsigned" => true, "notnull" => true, "default" => 1]);
        }

        $this->addRecordItemsToTable($recordTable, $record);

        $recordTable->addForeignKeyConstraint(static::quoteString($parentLevelName), [static::quoteString($parentId)], [static::quoteString($parentId)], ["onDelete" => "CASCADE"]);
        $recordTable->addIndex([static::quoteString($parentId)]);
    }

    public function addRecordItemsToTable(Table $table, Record $record) {
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
                $this->addRecordItemToTable($table, $item);
            }
        }
    }

    public function addRecordItemToTable(Table $table, Item $item) {
        $itemName = strtolower($item->getName());
        $itemType = static::generateColumnType($item);
        $itemOccurrences = $item->getItemSubitemOccurs();
        $options = ["notnull" => false];
        if ($itemType === self::COLUMN_TYPE_DECIMAL) {
            $options["precision"] = $item->getLength();
            $options["scale"] = $item->getDecimalPlaces();
        }
        if ($itemOccurrences == 1) {
            $table->addColumn($itemName, $itemType, $options);
        } else {
            for ($occurrence = 1; $occurrence <= $itemOccurrences; $occurrence++) {
                $itemNameWithOccurrence = $itemName . '(' . $occurrence . ')';
                $table->addColumn(static::quoteString($itemNameWithOccurrence), $itemType, $options);
            }
        }
    }

    public function createDefaultTables() {
//cases 
        /* "CREATE TABLE cases ("
          "id TEXT NOT NULL,"
          "`key` TEXT NOT NULL,"
          "label TEXT,"
          "questionnaire TEXT NOT NULL,"
          "last_modified_revision INTEGER NOT NULL,"
          "deleted INTEGER NOT NULL DEFAULT 0,"
          "verified INTEGER NOT NULL DEFAULT 0,"
          "partial_save_mode TEXT NULL,"
          "partial_save_field_name TEXT NULL,"
          "partial_save_level_key TEXT NULL,"
          "partial_save_record_occurrence INTEGER NULL,"
          "partial_save_item_occurrence INTEGER NULL,"
          "partial_save_subitem_occurrence INTEGER NULL,"
          "FOREIGN KEY(last_modified_revision) REFERENCES file_revisions(id)"
          ");\n" */
        $casesTable = $this->schema->createTable(static::quoteString('cases'));
        $casesTable->addColumn(static::quoteString('id'), "text", ["notnull" => true]);
        $casesTable->addColumn(static::quoteString('key'), "text", ["notnull" => true]);
        $casesTable->addColumn(static::quoteString('label'), "text");
        $casesTable->addColumn(static::quoteString('questionnaire'), "text", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('last_modified_revision'), "integer", ["notnull" => true]);
        $casesTable->addColumn(static::quoteString('deleted'), "integer", ["notnull" => true, "default" => 0]);
        $casesTable->addColumn(static::quoteString('verified'), "integer", ["notnull" => true, "default" => 0]);
        $casesTable->addColumn(static::quoteString('partial_save_mode'), "text", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('partial_save_field_name'), "text", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('partial_save_level_key'), "text", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('partial_save_record_occurrence'), "integer", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('partial_save_item_occurrence'), "integer", ["notnull" => false, "default" => null]);
        $casesTable->addColumn(static::quoteString('partial_save_subitem_occurrence'), "integer", ["notnull" => false, "default" => null]);

        $casesTable->addUniqueIndex(["`id`"], null, ["lengths" => [191]]);
        $casesTable->addIndex([static::quoteString('deleted')]);
//notes
        /*     "CREATE TABLE notes ("
          "case_id TEXT NOT NULL,"
          "field_name TEXT NOT NULL,"
          "level_key TEXT NOT NULL,"
          "record_occurrence INTEGER NOT NULL,"
          "item_occurrence INTEGER NOT NULL,"
          "subitem_occurrence INTEGER NOT NULL,"
          "content TEXT NOT NULL,"
          "operator_id TEXT NOT NULL,"
          "modified_time INTEGER NOT NULL,"
          "FOREIGN KEY(case_id) REFERENCES cases(id)"
          ");\n"
          "CREATE INDEX `notes-case-id` ON notes(case_id);"; */
        $notesTable = $this->schema->createTable(static::quoteString('notes'));
        $notesTable->addColumn(static::quoteString('case_id'), "text", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('field_name'), "text", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('level_key'), "text", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('record_occurrence'), "integer", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('item_occurrence'), "integer", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('subitem_occurrence'), "integer", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('content'), "text", ["notnull" => true]);
        $notesTable->addColumn(static::quoteString('operator_id'), "text", ["notnull" => true]);
        $notesTable->addColumn("`modified_time`", "datetime", ['columnDefinition' => 'timestamp']);
        $notesTable->addIndex([static::quoteString('case_id')], null, [], ["lengths" => [191]]);
        //DBAL has issues with creating foreign key constraint on text columns with lengths. 
        //not adding for now, if needed add it in the future
//        $notesTable->addForeignKeyConstraint($this->quoteString('cases'), array($this->quoteString('case_id')), array($this->quoteString('id')),
//                array("lengths" => array(191,191)), 'notes_cases_fk');

        /* CREATE TABLE IF NOT EXISTS `cspro_jobs` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `startCaseId` int unsigned NOT NULL,
          `startRevision` int unsigned NOT NULL,
          `endCaseId` int unsigned NOT NULL,
          `endRevision` int unsigned NOT NULL,
          `casesProcessed` int unsigned  NULL,
          `created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
          ) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; */
        $jobsTable = $this->schema->createTable(static::quoteString('cspro_jobs'));
        $jobsTable->addColumn("`id`", "integer", ["unsigned" => true, "notnull" => true, "autoincrement" => true]);
        $jobsTable->addColumn("`start_caseid`", "integer", ["unsigned" => true, "notnull" => true]);
        $jobsTable->addColumn("`start_revision`", "integer", ["unsigned" => true, "notnull" => true]);
        $jobsTable->addColumn("`end_caseid`", "integer", ["unsigned" => true, "notnull" => true]);
        $jobsTable->addColumn("`end_revision`", "integer", ["unsigned" => true, "notnull" => true]);
        $jobsTable->addColumn("`cases_to_process`", "integer", ["unsigned" => true, "notnull" => false, "default" => null]);
        $jobsTable->addColumn("`cases_processed`", "integer", ["unsigned" => true, "notnull" => false, "default" => null]);
        $jobsTable->addColumn("`status`", "integer", ["unsigned" => true, "notnull" => true, "default" => 0]);
        $jobsTable->addColumn("`created_time`", "datetime", ['columnDefinition' => 'timestamp default current_timestamp']);
        $jobsTable->addColumn("`modified_time`", "datetime", ['columnDefinition' => 'timestamp default current_timestamp on update current_timestamp']);
        $jobsTable->setPrimaryKey(["`id`"]);

        //Create meta table 
        $metaTable = $this->schema->createTable(static::quoteString('cspro_meta'));
        $metaTable->addColumn("`id`", "integer", ["unsigned" => true, "notnull" => true, "autoincrement" => true]);
        $metaTable->addColumn("`cspro_version`", "text", ["notnull" => true]);
        $metaTable->addColumn("`dictionary`", "text", ["notnull" => true]);
        $metaTable->addColumn("`source_modified_time`", "datetime", ["default" => null]);
        $metaTable->addColumn("`created_time`", "datetime", ['columnDefinition' => 'timestamp default current_timestamp']);
        $metaTable->addColumn("`modified_time`", "datetime", ['columnDefinition' => 'timestamp default current_timestamp on update current_timestamp']);
        $metaTable->setPrimaryKey(["`id`"]);
    }

    public static function quoteString(string $str): string {
        return "`" . $str . "`";
    }

}
