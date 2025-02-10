<?php

namespace AppBundle\CSPro\Dictionary;

/**
 * Relation between two records in a CSPro dictionary.
 *
 */
class Relation extends DictBase {

    /** @var string Name of primary record */
    private $primary;

    /** @var string Name of item on primary record used to link or null if linked on occurrence */
    private $primaryLink;

    /** @var string Name of secondary record */
    private $secondary;

    /** @var string Name of item on secondary record used to link or null if linked on occurrence */
    private $secondaryLink;

    /** @var array RelationParts  */
    private $links;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->primary = $attributes['Primary'];
            $this->primaryLink = $attributes['PrimaryLink'] ?? null;
            $this->secondary = $attributes['Secondary'];
            $this->secondaryLink = $attributes['SecondaryLink'] ?? null;
        } else {
            $this->primary = $attributes[DictionaryKeys::primary];
            $this->links = $attributes[DictionaryKeys::links];
        }
    }

    public function toArray($languages): array {
        $relation = parent::toArray($languages);
        $relation[DictionaryKeys::primary] = $this->primary;
        $relation[DictionaryKeys::links] = [];
        foreach ($this->links as $link) {
            $relation[DictionaryKeys::links][] = $link->toArray($languages);
        }
        return $relation;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function getPrimary() {
        return $this->primary;
    }

    public function setPrimary($primary) {
        $this->primary = $primary;
    }

    public function getPrimaryLink() {
        return $this->primaryLink;
    }

    public function setPrimaryLink($primaryLink) {
        $this->primaryLink = $primaryLink;
    }

    public function getSecondary() {
        return $this->secondary;
    }

    public function setSecondary($secondary) {
        $this->secondary = $secondary;
    }

    public function getSecondaryLink() {
        return $this->secondaryLink;
    }

    public function setSecondaryLink($secondaryLink) {
        $this->secondaryLink = $secondaryLink;
    }

}

;
