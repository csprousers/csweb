<?php

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\DictBase;

/**
 * One value in a ValueSet.
 */
class Value extends DictBase {

    /** @var ValuePair[] Numeric/alpha values/ranges associated with this value */
    private $valuePairs;

    /** @var string|null Special value associated with this value (MISSING, NOTAPPL, DEFAULT) or null if not special */
    private $special;

    /** @var string|null Path to image for this file if it has an image, null otherwise */
    private $image;

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->valuePairs = $attributes['VPairs'];
            $this->special = $attributes['Special'] ?? null;
            $this->image = $attributes['Image'] ?? null;
        } else {
            $this->valuePairs = $attributes[DictionaryKeys::pairs];
            $this->special = $attributes[DictionaryKeys::special] ?? null;
            $this->image = $attributes[DictionaryKeys::image] ?? null;
        }
    }

    public function toArray($languages): array {
        $value = parent::toArray($languages);
        if (isset($this->image))
            $value[DictionaryKeys::image] = $this->image;

        foreach ($this->valuePairs as $vPair) {
            $value[DictionaryKeys::pairs][] = $vPair->toArray($languages);
            if (isset($this->special)) {
                $value[DictionaryKeys::special] = $this->special;
            }
        }
        return $value;
    }

    public function getValuePairs() : array {
        return $this->valuePairs;
    }

    public function setValuePairs($valuePairs) {
        $this->valuePairs = $valuePairs;
    }

    public function getSpecial() {
        return $this->special;
    }

    public function setSpecial($special) {
        $this->special = $special;
    }

    public function getImage() {
        return $this->image;
    }

    public function setImage($image) {
        $this->image = $image;
    }

}

;
