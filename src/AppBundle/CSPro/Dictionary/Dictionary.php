<?php

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\DictionaryKeys;

/**
 * CSPro data dictionary.
 *
 */
class Dictionary extends DictBase {

    /** @var string Version of CSPro used to create dictionary */
    private $version;

    /** @var string identifying CSPro as the software */
    private $software;

    /** @var bool identifying CSPro as the software */
    private $editable;

    /** @var string identifying dictionary as the filetype */
    private $fileType;

    /** @var int Start column that record type is stored in the data file */
    private $recordTypeStart;

    /** @var int Number of columns that record type occupies in the data file */
    private $recordTypeLength;

    /** @var string "Relative" or "Absolute" determines how new items are positioned in dictionary editors */
    private $positioning;

    /** @var bool Default value for zero fill setting on new items in dictionary editor */
    private $zeroFill;

    /** @var bool Default value for decimal char setting on new items in dictionary editor */
    private $decimalChar;

    /** @var Level[] List of levels starting with level 1 at index 0, level 2 at index 1... */
    private $levels;

    /** @var Language[] List of languages used in labels in dictionary ... */
    private $languages;

    /** @var Relation[] List of relations between dictionary records ... */
    private $relations;

    /** @var security - JSON node of the dictionary with security key value pairs ... */
    private $security;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->version = $attributes['Version'];
            $this->recordTypeStart = $attributes['RecordTypeStart'];
            $this->recordTypeLength = $attributes['RecordTypeLen'];
            $this->positioning = $attributes['Positions'];
            $this->zeroFill = $attributes['ZeroFill'];
            $this->decimalChar = $attributes['DecimalChar'];
            $this->levels = $attributes['Level'];
            $this->languages = $attributes['Languages'];
            $this->relations = $attributes['Relation'];
        } else {
            $this->software = $attributes[DictionaryKeys::software];
            $this->editable = $attributes[DictionaryKeys::editable];
            $this->version = $attributes[DictionaryKeys::version];
            $this->fileType = $attributes[DictionaryKeys::fileType];
            $this->recordTypeStart = $attributes[DictionaryKeys::start];
            $this->recordTypeLength = $attributes[DictionaryKeys::length];
            $this->positioning = $attributes[DictionaryKeys::relativePositions];
            $this->zeroFill = $attributes[DictionaryKeys::zeroFill];
            $this->decimalChar = $attributes[DictionaryKeys::decimalMark];
            $this->security = $attributes[DictionaryKeys::security] ?? null;
            $this->levels = $attributes[DictionaryKeys::levels];
            $this->languages = $attributes[DictionaryKeys::languages] ?? null;
            $this->relations = $attributes[DictionaryKeys::relations] ?? null;
        }
    }

    public function toArray($languages): array {
        ///call base class toArray
        $dictionary[DictionaryKeys::software] = $this->software;
        $dictionary[DictionaryKeys::editable] = $this->editable;
        $dictionary[DictionaryKeys::version] = $this->version;
        $dictionary[DictionaryKeys::fileType] = $this->fileType;
        $languages = $languages ?? $this->languages;

        $dictionary = $dictionary + parent::toArray($languages);
        $dictionary[DictionaryKeys::recordType][DictionaryKeys::start] = $this->recordTypeStart;
        $dictionary[DictionaryKeys::recordType][DictionaryKeys::length] = $this->recordTypeLength;
        if (isset($this->positioning))
            $dictionary[DictionaryKeys::relativePositions] = (bool) $this->positioning;
        $dictionary[DictionaryKeys::defaults][DictionaryKeys::decimalMark] = $this->decimalChar;
        $dictionary[DictionaryKeys::defaults][DictionaryKeys::zeroFill] = $this->zeroFill;
        $dictionary[DictionaryKeys::security] = $this->security;

        foreach ($this->languages as $language) {
            $dictionary[DictionaryKeys::languages][] = array(DictionaryKeys::name => $language->getName(), DictionaryKeys::label => $language->getLabel());
        }

        foreach ($this->levels as $level) {
            $dictionary[DictionaryKeys::levels][] = $level->toArray($this->languages);
        }
        if (isset($this->relations)) {
            foreach ($this->relations as $relation) {
                $dictionary[DictionaryKeys::relations][] = $relation->toArray($this->languages);
            }
        }

        return $dictionary;
    }

    /**
     * Get dictionary version. 
     *
     * @return string Version of CSPro used to create dictionary 
     */
    public function getVersion() {
        return $this->version;
    }

    /**
     * Set dictionary version. 
     *
     * @param string $version Version of CSPro used to create dictionary 
     */
    public function setVersion($version) {
        $this->version = $version;
    }

    public function getRecordTypeStart() {
        return $this->recordTypeStart;
    }

    public function setRecordTypeStart($recordTypeStart) {
        $this->recordTypeStart = $recordTypeStart;
    }

    public function getRecordTypeLength() {
        return $this->recordTypeLength;
    }

    public function setRecordTypeLength($recordTypeLength) {
        $this->recordTypeLength = $recordTypeLength;
    }

    public function getPositioning() {
        return $this->positioning;
    }

    public function setPositioning($positioning) {
        $this->positioning = $positioning;
    }

    public function getZeroFill() {
        return $this->zeroFill;
    }

    public function setZeroFill($zeroFill) {
        $this->zeroFill = $zeroFill;
    }

    public function getDecimalChar() {
        return $this->decimalChar;
    }

    public function setDecimalChar($decimalChar) {
        $this->decimalChar = $decimalChar;
    }

    public function getLevels() {
        return $this->levels;
    }

    public function setLevels($levels) {
        $this->levels = $levels;
    }

    /**
     * Find item in dictionary by name.
     *
     * @param string $itemName Name of item to search for
     * @return array Triple (item, record, level) or false if item not found
     */
    public function findItem($itemName) {
        $matchItem = fn($item) => $item->getName() == $itemName;

        foreach ($this->levels as $level) {

            $item = current(array_filter($level->getIdItems(), $matchItem));
            if ($item)
                return [$item, null, $level];

            foreach ($level->getRecords() as $record) {
                $item = current(array_filter($record->getItems(), $matchItem));
                if ($item)
                    return [$item, $record, $level];
            }
        }

        return false;
    }

    /**
     * Find binary items  in dictionary.
     * @return array items or false if item not found
     */
    public function getBinaryItemList(): array {
        $matchItem = fn($item) => (!$item->isNumeric() && !$item->isAlpha());
        $binaryItemList = array();
        foreach ($this->levels as $level) {

            $binaryItemList = array_merge($binaryItemList, array_filter($level->getIdItems(), $matchItem));

            foreach ($level->getRecords() as $record) {
                $binaryItemList = array_merge($binaryItemList, array_filter($record->getItems(), $matchItem));
            }
        }
        return $binaryItemList;
    }

    /**
     * Find binary items  in dictionary.
     * @return array items or false if item not found
     */
    public function hasBinaryItems(): bool {
        $matchItem = fn($item) => (!$item->isNumeric() && !$item->isAlpha());
        $binaryItemList = array();
        foreach ($this->levels as $level) {

            $binaryItemList = array_merge($binaryItemList, array_filter($level->getIdItems(), $matchItem));
            //echo 'Binary Item List ' . print_r($binaryItemList);
            if (count($binaryItemList) > 0)
                return true;
            foreach ($level->getRecords() as $record) {
                $binaryItemList = array_merge($binaryItemList, array_filter($record->getItems(), $matchItem));
                //echo 'Binary Item List (record items ' . print_r($binaryItemList);
                if (count($binaryItemList) > 0)
                    return true;
            }
        }
        return false;
    }

    /**
     * Find record in dictionary by name
     *
     * @param string $recordName Name of record to search for
     * @return Record Record or false if record not found
     */
    public function findRecord($recordName) {
        $matchRecord = fn($record) => $record->getName() == $recordName;

        foreach ($this->levels as $level) {
            $record = current(array_filter($level->getRecords(), $matchRecord));
            if ($record)
                return $record;
        }

        return false;
    }

    /**
     * Find item that contains a given subitem
     *
     * @param Item $subitemName Name of subitem
     * @return Item Parent item of subitem or false if not found
     */
    public function findSubitemParent($subitemName) {
        [$subitem, $record, $level] = $this->findItem($subitemName);
        if ($subitem == false)
            return false;

        $matchSubitemParent = fn($item) => $item != $subitem &&
                $item->getItemType() == 'Item' &&
                $item->getStart() <= $subitem->getStart() &&
                $item->getStart() + $item->getLength() >= $subitem->getStart() + $subitem->getLength();

        return current(array_filter($record->getItems(), $matchSubitemParent));
    }

}

;
