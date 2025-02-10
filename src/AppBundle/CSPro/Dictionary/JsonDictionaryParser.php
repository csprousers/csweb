<?php

namespace AppBundle\CSPro\Dictionary;

use AppBundle\CSPro\Dictionary\Dictionary;
use AppBundle\CSPro\Dictionary\Level;
use AppBundle\CSPro\Dictionary\Record;
use AppBundle\CSPro\Dictionary\Item;
use AppBundle\CSPro\Dictionary\ValueSet;
use AppBundle\CSPro\Dictionary\Value;
use AppBundle\CSPro\Dictionary\ValuePair;
use AppBundle\CSPro\Dictionary\DictionaryKeys;

class JsonDictionaryParser {

    //TODO: add logger? 
    private $dictionaryStructure = null;
    private $attributes = [];
    private $jsonDictionary = null;

    public function __construct() {
        
    }

    public function getJSONDictionary() {
        return $this->jsonDictionary;
    }

    public function parseDictionary(string $dictText): ?Dictionary {

        try {
            // Remove the byte order marker
            $bom = pack('H*', 'EFBBBF');
            $dictText = preg_replace("/^$bom/", '', $dictText);
            $this->jsonDictionary = json_decode($dictText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            throw $ex;
        }
        $this->readDictionaryProperties(); //TODOL check if the attributes are valid
        $this->dictionaryStructure = new Dictionary($this->attributes, false);

        return $this->dictionaryStructure;
    }

    private function readDictionaryProperties(): void {
        $this->attributes[DictionaryKeys::software] = $this->jsonDictionary[DictionaryKeys::software] ?? ""; //TODO: use software in attributes
        $this->attributes[DictionaryKeys::editable] = $this->jsonDictionary[DictionaryKeys::editable] ?? ""; //TODO: use software in attributes
        $this->attributes[DictionaryKeys::version] = $this->jsonDictionary[DictionaryKeys::version] ?? "";
        $this->attributes[DictionaryKeys::fileType] = $this->jsonDictionary[DictionaryKeys::fileType] ?? "";
        $this->attributes[DictionaryKeys::name] = $this->jsonDictionary[DictionaryKeys::name] ?? "";

        //read languages 
        $dictLanguages = $this->jsonDictionary[DictionaryKeys::languages] ?? null;
        $this->attributes[DictionaryKeys::languages] = is_countable($dictLanguages) ? array_map(fn($language) => new Language($language[DictionaryKeys::name] ?? "", $language[DictionaryKeys::label] ?? "") ?? "", $dictLanguages) : [];
        //TODO: relations 
        $this->languageKeyIndexMap = [];
        $languages = $this->attributes[DictionaryKeys::languages];
        foreach ($languages as $index => $language) {
            $this->languageKeyIndexMap[$language->getName()] = $index;
        }

        $this->attributes[DictionaryKeys::labels] = $this->readLabels($this->jsonDictionary[DictionaryKeys::labels]);
        $this->attributes[DictionaryKeys::note] = $this->jsonDictionary[DictionaryKeys::note] ?? "";

        //security
        $this->attributes[DictionaryKeys::security][DictionaryKeys::allowDataViewerModifications] = $this->jsonDictionary[DictionaryKeys::security][DictionaryKeys::allowDataViewerModifications] ?? false;
        $this->attributes[DictionaryKeys::security][DictionaryKeys::allowExport] = $this->jsonDictionary[DictionaryKeys::security][DictionaryKeys::allowExport] ?? false;
        $this->attributes[DictionaryKeys::security][DictionaryKeys::cachedPasswordMinutes] = $this->jsonDictionary[DictionaryKeys::security][DictionaryKeys::cachedPasswordMinutes] ?? 0;
        $this->attributes[DictionaryKeys::security][DictionaryKeys::settings] = $this->jsonDictionary[DictionaryKeys::security][DictionaryKeys::settings] ?? "";

        //recordType
        $this->attributes[DictionaryKeys::start] = $this->jsonDictionary[DictionaryKeys::recordType][DictionaryKeys::start] ?? 1;
        $this->attributes[DictionaryKeys::length] = $this->jsonDictionary[DictionaryKeys::recordType][DictionaryKeys::length] ?? null;

        //defaults
        $this->attributes[DictionaryKeys::decimalMark] = $this->jsonDictionary[DictionaryKeys::defaults][DictionaryKeys::decimalMark] ?? false;
        $this->attributes[DictionaryKeys::zeroFill] = $this->jsonDictionary[DictionaryKeys::defaults][DictionaryKeys::zeroFill] ?? true;

        $this->attributes[DictionaryKeys::relativePositions] = $this->jsonDictionary[DictionaryKeys::relativePositions] ?? true;
        $this->attributes[DictionaryKeys::levels] = $this->readDictionaryLevels();

        //relations
        $relationsJSON = $this->jsonDictionary[DictionaryKeys::relations] ?? null;
        if (isset($relationsJSON)) {
            foreach ($relationsJSON as $relationJSON) {
                $relationAttributes = [];
                $relationAttributes[DictionaryKeys::name] = $relationJSON[DictionaryKeys::name];
                $relationAttributes[DictionaryKeys::labels] = $relationJSON[DictionaryKeys::labels] ?? [];
                $relationAttributes[DictionaryKeys::primary] = $relationJSON[DictionaryKeys::primary];
                $links = $relationJSON[DictionaryKeys::links];
                $relationAttributes[DictionaryKeys::links] = [];
                foreach ($links as $link) {
                    $linkAttributes = [];
                    $linkAttributes[DictionaryKeys::primaryLink] = $link[DictionaryKeys::primaryLink];
                    $linkAttributes[DictionaryKeys::secondary] = $link[DictionaryKeys::secondary];
                    $linkAttributes[DictionaryKeys::secondaryLink] = $link[DictionaryKeys::secondaryLink];
                    $relationAttributes[DictionaryKeys::links][] = new RelationPart($linkAttributes);
                }
                $this->attributes[DictionaryKeys::relations][] = new Relation($relationAttributes, false);
            }
        }
    }

    private function readLabels($jsonLabels): array {
        //returns a sparse array of array of label texts 
        $labels = [];
        //example of labels: "labels": [ { "text": "labelEng" }, { "text": "labelFr" }]
        foreach (is_countable($jsonLabels) ? $jsonLabels : array() as $label) {
            $labelText = $label[DictionaryKeys::text] ?? "";
            $labelLanguage = $label[DictionaryKeys::language] ?? "";
            if (empty($labelLanguage)) {
                $labels[0] = $labelText;
            } else {//set the label
                $labelLanguageIndex = $this->languageKeyIndexMap[$labelLanguage] ?? null;
                if (isset($labelLanguageIndex) && $labelLanguageIndex >= 0) {
                    $labels[$labelLanguageIndex] = $labelText;
                }
            }
        }
        return $labels;
    }

    private function readDictionaryLevels(): array {

        //read DictBase Attributes [name, label, note]
        $readDictBaseAttributes = function ($jsonNode) {
            $attributes = [];
            $attributes[DictionaryKeys::name] = $jsonNode[DictionaryKeys::name] ?? "";
            $attributes[DictionaryKeys::labels] = is_countable($jsonNode[DictionaryKeys::labels]) ? $this->readLabels($jsonNode[DictionaryKeys::labels]) : [];
            $attributes[DictionaryKeys::note] = $jsonNode[DictionaryKeys::note] ?? null;
            return $attributes;
        };
        //read vset 
        $readVSet = function ($jsonVSet) use ($readDictBaseAttributes) {
            //read vset name and labels 
            $attributes = $readDictBaseAttributes($jsonVSet);
            $attributes[DictionaryKeys::link] = $jsonVSet[DictionaryKeys::link] ?? null;

            $vsetValues = $jsonVSet[DictionaryKeys::values] ?? [];
            $values = [];
            foreach ($vsetValues as $vsetValue) {
                $valueAttributes = $readDictBaseAttributes($vsetValue);
                $valueAttributes[DictionaryKeys::special] = $vsetValue[DictionaryKeys::special] ?? null;
                $valueAttributes[DictionaryKeys::image] = $vsetValue[DictionaryKeys::image] ?? null;

                //read value pairs 
                $vPairs = $vsetValue[DictionaryKeys::pairs] ?? [];
                $valuePairs = [];
                foreach ($vPairs as $vPair) {
                    $vpairAttributes = [];
                    if (isset($vPair[DictionaryKeys::value])) {
                        $vpairAttributes['From'] = $vPair[DictionaryKeys::value];
                    } else if (isset($vPair[DictionaryKeys::range])) {
                        $vpairAttributes['From'] = $vPair[DictionaryKeys::range][0] ?? null;
                        $vpairAttributes['To'] = $vPair[DictionaryKeys::range][1] ?? null;
                    }
                    $valuePairs[] = new ValuePair($vpairAttributes);
                }
                $valueAttributes[DictionaryKeys::pairs] = $valuePairs;
                $values[] = new Value($valueAttributes, false);
            }
            $attributes[DictionaryKeys::values] = $values;
            $vSet = new ValueSet($attributes, false);
            return $vSet;
        };

        //read item
        $readItem = function ($jsonItem) use ($readDictBaseAttributes, $readVSet) {
            $attributes = $readDictBaseAttributes($jsonItem);
            $attributes[DictionaryKeys::contentType] = $jsonItem[DictionaryKeys::contentType] ?? "";
            $attributes[DictionaryKeys::subitem] = $jsonItem[DictionaryKeys::subitem] ?? false;
            $attributes[DictionaryKeys::subitemOffset] = $jsonItem[DictionaryKeys::subitemOffset] ?? null;
            $attributes[DictionaryKeys::start] = $jsonItem[DictionaryKeys::start] ?? null;
            $attributes[DictionaryKeys::length] = $jsonItem[DictionaryKeys::length] ?? 0;
            $attributes[DictionaryKeys::decimals] = $jsonItem[DictionaryKeys::decimals] ?? 0;
            $attributes[DictionaryKeys::decimalMark] = $jsonItem[DictionaryKeys::decimalMark] ?? false;
            $attributes[DictionaryKeys::zeroFill] = $jsonItem[DictionaryKeys::zeroFill] ?? null;
            if (isset($jsonItem[DictionaryKeys::occurrences])) {
                $attributes[DictionaryKeys::maximum] = $jsonItem[DictionaryKeys::occurrences][DictionaryKeys::maximum] ?? 0;
                $jsonOccLabels = $jsonItem[DictionaryKeys::occurrences][DictionaryKeys::labels] ?? [];
                $occurrenceLabels = [];
                foreach ($jsonOccLabels as $jsonOccLabel) {
                    if (isset($jsonOccLabel[DictionaryKeys::occurrence])) {
                        $occurrenceLabels[$jsonOccLabel[DictionaryKeys::occurrence]] = $this->readLabels($jsonOccLabel[DictionaryKeys::labels]);
                    }
                }
                $attributes[DictionaryKeys::occurrences] = $occurrenceLabels;
            }
            //TODO captureinfo
            //
            //read valuesets 
            $valueSets = [];
            $jsonValueSets = $jsonItem[DictionaryKeys::valueSets] ?? [];
            foreach ($jsonValueSets as $jsonVSet) {
                $valueSets[] = $readVSet($jsonVSet);
            }
            $attributes[DictionaryKeys::valueSets] = $valueSets;
            //new Item with attributes and set the pre80 flag to false
            $item = new Item($attributes, false);
            return $item;
        };

        $readRecord = function ($jsonRecord) use ($readDictBaseAttributes, $readItem) {

            $attributes = $readDictBaseAttributes($jsonRecord);
            $attributes[DictionaryKeys::recordType] = $jsonRecord[DictionaryKeys::recordType] ?? "";
            $attributes[DictionaryKeys::required] = $jsonRecord[DictionaryKeys::occurrences][DictionaryKeys::required] ?? false;
            $attributes[DictionaryKeys::maximum] = $jsonRecord[DictionaryKeys::occurrences][DictionaryKeys::maximum] ?? 0;

            // read record occurence labels 
            $jsonOccLabels = $jsonRecord[DictionaryKeys::occurrences][DictionaryKeys::labels] ?? [];
            $occurrenceLabels = [];
            foreach ($jsonOccLabels as $jsonOccLabel) {
                if (isset($jsonOccLabel[DictionaryKeys::occurrence])) {
                    $occurrenceLabels[$jsonOccLabel[DictionaryKeys::occurrence]] = $this->readLabels($jsonOccLabel[DictionaryKeys::labels]);
                }
            }
            $attributes[DictionaryKeys::occurrences] = $occurrenceLabels;

            //read items 
            $items = [];
            $jsonItems = $jsonRecord[DictionaryKeys::items] ?? [];
            foreach ($jsonItems as $jsonItem) {
                $items[] = $readItem($jsonItem);
            }
            $attributes[DictionaryKeys::items] = $items;
            //new Record with attributes and set the pre80 flag to false
            $record = new Record($attributes, false);
            return $record;
        };

        $levels = [];
        $jsonLevels = $this->jsonDictionary[DictionaryKeys::levels] ?? [];
        foreach ($jsonLevels as $jsonLevel) {
            $idItems = [];
            $records = [];
            //read level name and labels 
            $attributes = $readDictBaseAttributes($jsonLevel);
            //read the id items
            $jsonIdItems = $jsonLevel[DictionaryKeys::ids][DictionaryKeys::items] ?? [];
            foreach ($jsonIdItems as $jsonItem) {
                $idItems[] = $readItem($jsonItem);
            }
            //read records
            $jsonRecords = $jsonLevel[DictionaryKeys::records] ?? [];
            foreach ($jsonRecords as $jsonRecord) {
                $records[] = $readRecord($jsonRecord);
            }
            $attributes[DictionaryKeys::ids] = $idItems;
            $attributes[DictionaryKeys::records] = $records;
            $levels[] = new Level($attributes, false);
        }
        return $levels;
    }

    public static function isValidJSON(string $dictText): bool {
        // Remove the byte order marker
        $bom = pack('H*', 'EFBBBF');
        $dictText = preg_replace("/^$bom/", '', $dictText);

        json_decode($dictText);
        return json_last_error() === JSON_ERROR_NONE;
    }

}
