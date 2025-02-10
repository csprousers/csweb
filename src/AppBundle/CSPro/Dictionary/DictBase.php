<?php

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\DictionaryKeys;

/**
 * Basic attributes shared by multiple object types in dictionary.
 */
class DictBase {

    /** @var string Element name */
    private $name;

    /** @var string[] Labels, one per language */
    private array $labels;

    /** @var string|null User notes */
    private $note;

    /** @var string|null User notes */
    private $blobBreakOutInclude;
    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        $this->blobBreakOutInclude = true;
        if ($pre80Dictionary) {
            $this->name = $attributes['Name'];
            $this->labels = $attributes['Label'];
            if (isset($attributes['Note']))
                $this->note = $attributes['Note'];
        } else {
            $this->name = $attributes[DictionaryKeys::name];
            $this->labels = $attributes[DictionaryKeys::labels] ?? [];
            $this->note = $attributes[DictionaryKeys::note] ?? null;
        }
    }

    public function toArray($languages): array {
        $dictBase[DictionaryKeys::name] = $this->name;
        if (isset($languages) && count($languages) > 0) {
            foreach ($languages as $index => $language) {
                if (isset($this->labels[$index])) {
                    $dictBase[DictionaryKeys::labels][] = array(DictionaryKeys::text => $this->labels[$index], DictionaryKeys::language => $language->getName());
                }
            }
        } else {
            $dictBase[DictionaryKeys::labels][] = array(DictionaryKeys::text => $this->labels[0] ?? "");
        }
        if (isset($this->note)) {
            $dictBase[DictionaryKeys::note] = $this->note;
        }
        return $dictBase;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function getLabel($languageIndex = 0) {
        return $this->labels[$languageIndex] ?? $this->labels[0] ?? ""; //labels starting v8.0 may not be set if the text for a language does not exist
    }

    public function setLabel($label, $languageIndex = 0) {
        $this->labels[$languageIndex] = $label;
    }

    public function getNote() {
        return $this->note;
    }

    public function setNote($note) {
        $this->note = $note;
    }

    public function isIncludedInBlobBreakOut() {
        return $this->blobBreakOutInclude;
    }
    
    //used by records and items in blob break out 
    public function includeInBlobBreakOut($flag) {
        $this->blobBreakOutInclude = $flag;
    }
}

;
