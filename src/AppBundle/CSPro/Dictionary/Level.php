<?php

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\DictionaryKeys;

/**
 * Level in a CSPro dictionary.
 *
 */
class Level extends DictBase {

    /** @var Item[] List of id variables for this level */
    private $idItems;

    /** @var Record[] List of records for this level */
    private $records;

    /** @var Record[] List of records for this level */
    private $levelNumber;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->idItems = $attributes['IdItems'];
            $this->records = $attributes['Record'];
        } else {
            $this->idItems = $attributes[DictionaryKeys::ids];
            $this->records = $attributes[DictionaryKeys::records];
        }
    }

    public function toArray($languages): array {
        $level = parent::toArray($languages);
        foreach ($this->idItems as $idItem) {
            $level[DictionaryKeys::ids][DictionaryKeys::items][] = $idItem->toArray($languages);
        }
        foreach ($this->records as $record) {
            $level[DictionaryKeys::records][] = $record->toArray($languages);
        }
        return $level;
    }

    public function getIdItems() {
        return $this->idItems;
    }

    public function setIdItems($idItems) {
        $this->idItems = $idItems;
    }

    public function getRecords() {
        return $this->records;
    }

    public function setRecords($records) {
        $this->records = $records;
    }

    public function getLevelNumber() {
        return $this->levelNumber;
    }

    public function setLevelNumber($levelNumber) {
        $this->levelNumber = $levelNumber;
    }

}

;
