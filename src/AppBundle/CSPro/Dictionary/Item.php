<?php

namespace AppBundle\CSPro\Dictionary;

/**
 * Variable in a CSPro dictionary.
 *
 * An Item will either be part of a record or one of the id-items for a level.
 *
 */
class Item extends DictBase {

    /** @var int Start column in data file */
    private $start;

    /** @var int Width in digits (item spans from $start to $start + $length) */
    private $length;

    /** @var string "Alpha" (string) or "Numeric" (number) */
    private $dataType;

    /** @var string "Item" or "Subitem" */
    private $itemType;

    /** @var string "Item" or "Subitem" */
    private $subItemOffset;

    /** @var int Number of repetitions of a repeating item */
    private $occurrences;

    /** @var int Number of digits after decimal point */
    private $decimalPlaces;

    /** @var bool True if decimal is used, false otherwise (i.e. false if fixed point) */
    private $decimalChar;

    /** @var bool True if leading digits are filled with zeros instead of spaces (numeric only) */
    private $zeroFill;

    /** @var ValueSet[] List of value sets (possible responses) for this variable */
    private $valueSets;

    /** @var string[] List of occurrence labels (repeating items only) */
    private array $occurrenceLabels; // 

    /** @var reference to the parent Item - used to determine subitem's parent */
    private $parentItem; // 

    /**
     * Create from array of constructor parameters.
     *
     * @param array $attributes
     */
    public function __construct($attributes, $pre80Dictionary = true) {
        parent::__construct($attributes, $pre80Dictionary);
        if ($pre80Dictionary) {
            $this->start = $attributes['Start'];
            $this->length = $attributes['Len'];
            $this->itemType = $attributes['ItemType'];
            $this->dataType = $attributes['DataType'];
            $this->occurrences = $attributes['Occurrences'];
            $this->decimalChar = $attributes['DecimalChar'];
            $this->decimalPlaces = $attributes['Decimal'];
            $this->zeroFill = $attributes['ZeroFill'];
            $this->valueSets = $attributes['ValueSet'];
            $this->occurrenceLabels = $attributes['OccurrenceLabel'] ?? [];
        } else {
            $this->start = $attributes[DictionaryKeys::start];
            $this->length = $attributes[DictionaryKeys::length];
            $this->itemType = $attributes[DictionaryKeys::subitem] ? "SubItem" : "Item"; //item type is not explicitly written for items 
            $this->subItemOffset = $attributes[DictionaryKeys::subitemOffset];
            $this->dataType = $attributes[DictionaryKeys::contentType];
            $this->occurrences = $attributes[DictionaryKeys::maximum] ?? 1;
            $this->decimalChar = $attributes[DictionaryKeys::decimalMark];
            $this->decimalPlaces = $attributes[DictionaryKeys::decimals];
            $this->zeroFill = $attributes[DictionaryKeys::zeroFill];
            $this->valueSets = $attributes[DictionaryKeys::valueSets];
            $this->occurrenceLabels = $attributes[DictionaryKeys::occurrences] ?? [];
        }
    }

    public function toArray($languages): array {
        $dictItem = parent::toArray($languages);
        $dictItem[DictionaryKeys::contentType] = $this->dataType;
        if (isset($this->start))
            $dictItem[DictionaryKeys::start] = $this->start;
        $dictItem[DictionaryKeys::length] = $this->length;
        if ($this->itemType == "SubItem") {
            $dictItem[DictionaryKeys::subitem] = true;
            if (isset($this->subItemOffset))
                $dictItem[DictionaryKeys::subitemOffset] = $this->subItemOffset;
        }
        if (isset($this->decimalChar)) {
            $dictItem[DictionaryKeys::decimalMark] = $this->decimalChar;
        }
        if ($this->decimalPlaces > 0) {
            $dictItem[DictionaryKeys::decimals] = $this->decimalPlaces;
        }
        if (isset($this->zeroFill)) {
            $dictItem[DictionaryKeys::zeroFill] = $this->zeroFill;
        }
        foreach ($this->valueSets as $vSet) {
            $dictItem[DictionaryKeys::valueSets][] = $vSet->toArray($languages);
        }
        //occ labels
        if ($this->occurrences > 1) {
            $occ = new \stdClass();
            $occ->maximum = $this->occurrences;
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
                            $occLabelObj->labels[] = (object) array(DictionaryKeys::text => $occLabel[$languageIndex], DictionaryKeys::language => $language->getName());
                        }
                    }
                } else {
                    $occLabelObj->labels[] = (object) array(DictionaryKeys::text => $occLabel[0]);
                }
                $occLabelArray[] = $occLabelObj;
            }
            $occ->labels = (object) $occLabelArray;
            $dictItem[DictionaryKeys::occurrences] = (array) $occ;
        }

        return $dictItem;
    }

    public function getStart() {
        return $this->start;
    }

    public function setStart($start) {
        $this->start = $start;
    }

    public function getLength() {
        return $this->length;
    }

    public function setLength($length) {
        $this->length = $length;
    }

    public function getDataType() {
        return $this->dataType;
    }

    public function setDataType($dataType) {
        $this->dataType = $dataType;
    }

    public function getItemType() {
        return $this->itemType;
    }

    public function setItemType($itemType) {
        $this->itemType = $itemType;
    }

    public function getOccurrences() {
        return $this->occurrences;
    }

    public function setOccurrences($occurrences) {
        $this->occurrences = $occurrences;
    }

    public function getDecimalPlaces() {
        return $this->decimalPlaces;
    }

    public function setDecimalPlaces($decimalPlaces) {
        $this->decimalPlaces = $decimalPlaces;
    }

    public function getDecimalChar() {
        return $this->decimalChar;
    }

    public function setDecimalChar($decimalChar) {
        $this->decimalChar = $decimalChar;
    }

    public function getZeroFill() {
        return $this->zeroFill;
    }

    public function setZeroFill($zeroFill) {
        $this->zeroFill = $zeroFill;
    }

    public function getValueSets() {
        return $this->valueSets;
    }

    public function setValueSets($valueSets) {
        $this->valueSets = $valueSets;
    }

    public function getOccurrenceLabels() {
        return $this->occurrenceLabels;
    }

    public function setOccurrenceLabels($occurrenceLabels) {
        $this->occurrenceLabels = $occurrenceLabels;
    }

    public function setParentItem(?Item $parentItem) {
        $this->parentItem = $parentItem;
    }

    public function getParentItem() {
        return $this->parentItem;
    }

    public function getItemSubitemOccurs() {
        $occurs = $this->getOccurrences();
        //if item occurs then use it's value if not check it's parent 
        if ($occurs == 1 && $this->getParentItem()) {
            $occurs = $this->getParentItem()->getOccurrences();
        }
        return $occurs;
    }

    public function isNumeric() : bool 
    {
        return strcasecmp($this->dataType, 'Numeric') == 0; //case insensitive comparison to match older versions
    }
    public function isAlpha() : bool 
    {
        return strcasecmp($this->dataType, 'Alpha') == 0; //case insensitive comparison to match older versions
    }
}

;
