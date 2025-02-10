<?php

namespace AppBundle\CSPro\Dictionary;

/**
 * Relation between two records in a CSPro dictionary.
 *
 */
class RelationPart {

    /** @var string Name of item on primary record used to link or null if linked on occurrence */
    private $primaryLink;

    /** @var string Name of secondary record */
    private $secondary;

    /** @var string Name of item on secondary record used to link or null if linked on occurrence */
    private $secondaryLink;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes) {
        $this->primaryLink = $attributes[DictionaryKeys::primaryLink] ?? null;
        $this->secondary = $attributes[DictionaryKeys::secondary] ?? null;
        $this->secondaryLink = $attributes[DictionaryKeys::secondaryLink] ?? null;
    }

    public function toArray($languages = null): array {
        $link[DictionaryKeys::primaryLink] = $this->primaryLink;
        $link[DictionaryKeys::secondary] = $this->secondary;
        $link[DictionaryKeys::secondaryLink] = $this->secondaryLink;
        return $link;
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
