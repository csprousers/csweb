<?php

namespace AppBundle\CSPro\Dictionary;

/**
 * List of possible responses for an Item
 *
 */
class ValueSet extends DictBase {

    /** @var Value[] List of responses */
    private $values;

    /** @var string|null name of value set that this is linked to if this is a linked value set */
    private $linkedValueSet;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->values = $attributes['Value'];
            $this->linkedValueSet = $attributes['Link'] ?? null;
        } else {
            $this->values = $attributes[DictionaryKeys::values];
            $this->linkedValueSet = $attributes[DictionaryKeys::link] ?? null;
        }
    }

    public function toArray($languages): array {
        $vSet = parent::toArray($languages);
        $vSet[DictionaryKeys::values] = [];
        foreach ($this->values as $value) {
            $vSet[DictionaryKeys::values][] = $value->toArray($languages);
        }
        if (isset($this->linkedValueSet)) {
            $vSet[DictionaryKeys::link] = $this->linkedValueSet;
        }
        return $vSet;
    }

    public function getValues() {
        return $this->values;
    }

    public function setValues($values) {
        $this->values = $values;
    }

    public function getLinkedValueSet() {
        return $this->linkedValueSet;
    }

    public function setLinkedValueSet($linkedValueSet) {
        $this->linkedValueSet = $linkedValueSet;
    }

}
