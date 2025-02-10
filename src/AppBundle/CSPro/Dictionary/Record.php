<?php

namespace AppBundle\CSPro\Dictionary;

/**
 * Record in a CSPro dictionary.
 *
 * A record belongs to a Level and represents a group of variables
 * that appear on the same line in the data file.
 *
 */
class Record extends DictBase {

    /** @var string Tag used in data file to distinguish this type of record from others */
    private $typeValue;

    /** @var bool Whether or not this record is required to have a complete (valid) case */
    private $required;

    /** @var int Maximum number of records of this type allowed in a case. If > 1 then the record repeats. */
    private $maxRecords;

    /** @var int Total number of columns in data file used by this record */
    private $length;

    /** @var string[] List of occurrence labels (repeating records only) */
    private $occurrenceLabels;

    /** @var Item[] List of variables in this record */
    private $items;

    /** @var level of this record */
    private $level;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->typeValue = $attributes['RecordTypeValue'];
            $this->required = $attributes['Required'];
            $this->maxRecords = $attributes['MaxRecords'];
            $this->length = $attributes['RecordLen'];
            $this->occurrenceLabels = $attributes['OccurrenceLabel'];
            $this->items = $attributes['Item'];
        } else {
            $this->typeValue = $attributes[DictionaryKeys::recordType];
            $this->required = $attributes[DictionaryKeys::required];
            $this->maxRecords = $attributes[DictionaryKeys::maximum];
            $this->length = null; // not being set in the new 8.0 formats
            $this->occurrenceLabels = $attributes[DictionaryKeys::occurrences];
            $this->items = $attributes[DictionaryKeys::items];
        }
    }

    public function toArray($languages): array {
        $record = parent::toArray($languages);
        if (isset($this->typeValue))
            $record[DictionaryKeys::recordType] = $this->typeValue;

        $record[DictionaryKeys::occurrences] = array(DictionaryKeys::required => $this->required, DictionaryKeys::maximum => $this->maxRecords);
        $record[DictionaryKeys::items] = [];
        foreach ($this->items as $item) {
            $record[DictionaryKeys::items][] = $item->toArray($languages);
        }
        $recordOccs = new \stdClass();
        $recordOccs->required = $this->required;
        $recordOccs->maximum = $this->maxRecords;

        if (count($this->occurrenceLabels) > 1) {
            $occ = new \stdClass();
            $occLabelArray = [];
            foreach ($this->occurrenceLabels as $occLabelIndex => $occLabel) {
                if (!isset($occLabel)) {
                    continue;
                }
                $occLabelObj = new \stdClass();
                $occLabelObj->occurrence = $occLabelIndex;
                $occLabelObj->labels = [];
                if (isset($languages) && (is_countable($languages) ? count($languages) : 0) > 0) {
                    foreach ($languages as $languageIndex => $language) {
                        if (isset($occLabel[$languageIndex])) {
                            $occLabelObj->labels[] = array(DictionaryKeys::text => $occLabel[$languageIndex], DictionaryKeys::language => $language->getName());
                        }
                    }
                } else {
                    $occLabelObj->labels[] = array(DictionaryKeys::text => $occLabel[0]);
                }
                $occLabelArray[] = $occLabelObj;
            }
            $recordOccs->labels = $occLabelArray;
        }
        $record[DictionaryKeys::occurrences] = (array) $recordOccs;
        return $record;
    }

    public function getTypeValue() {
        return $this->typeValue;
    }

    public function setTypeValue($typeValue) {
        $this->typeValue = $typeValue;
    }

    public function getRequired() {
        return $this->required;
    }

    public function setRequired($required) {
        $this->required = $required;
    }

    public function getMaxRecords() {
        return $this->maxRecords;
    }

    public function setMaxRecords($maxRecords) {
        $this->maxRecords = $maxRecords;
    }

    public function getLength() {
        return $this->length;
    }

    public function setLength($length) {
        $this->length = $length;
    }

    public function getOccurrenceLabels() {
        return $this->occurrenceLabels;
    }

    public function setOccurrenceLabels($occurrenceLabels) {
        $this->occurrenceLabels = $occurrenceLabels;
    }

    public function getItems() {
        return $this->items;
    }

    public function setItems($items) {
        $this->items = $items;
    }

    public function setLevel($level) {
        $this->level = $level;
    }

    public function getLevel() {
        return $this->level;
    }

}
