<?php

namespace AppBundle\CSPro\Geocode;

class GeocodeParser {

    // Parse geocodes, return area names array
    function parseGeocodes($content, $headerRow, $logger, &$linesProcessedCount, $maxGeocodesImport=100) {
        // array_filter is used to filter out a length 0 array created from the empty string
        //    * A CSV with data will end with "\r\n." The explode generates an empty string at the final delimiter.
        $lines = array_filter(explode("\n", $content), 'strlen');
        $lineCount = count($lines);
        
        if ($lineCount > $maxGeocodesImport) {
            throw new \Exception("The maximum allowable number of area names to be created has been exceeded. There is a limit of $maxGeocodesImport area names that can be created at one time. Please break the file into smaller files and proceed.", 0);
        }

        $startLine = 0;
        if($headerRow === true) {
            $logger->debug('Header ignored by parser');
            $startLine = 1;
        }

        // Determine attribute count of geocode data
        $attrCount = count(str_getcsv($lines[$startLine], ',', '"', "\n"));

        $areaNames = [];
        // Exclude label field in count
        $areaNameAttrCount = $attrCount / 2;
        $AreaNameState = array_fill(0, $areaNameAttrCount, null);

        $geocodes = [];
        $parsedLines = 0;
        $duplicateLines = 0;
        for ($i = $startLine; $i < $lineCount; $i++) {
            // Expect n code and label pairs
            $csv = str_getcsv($lines[$i], ',', '"', "\n");
            $key = $this->CreateKey($csv);
            if (array_key_exists($key, $geocodes)) {
                $duplicateLines += 1;
            }
            else {
                $parsedLines += 1;
                $geocodes[$key] = 1;

                $updateState = false;
                for ($attrIndex = 0, $areaNameAttrIndex = 0; $attrIndex < $attrCount; $attrIndex+=2, $areaNameAttrIndex++) {
                    if ($csv[$attrIndex] !== $AreaNameState[$areaNameAttrIndex] || $updateState) {
                        // Mismatch! The current row's cell in the CSV is a new geography. Create area name entry for it.
                        // It follows that each cell to the right of this cell for the current row is a new geography too.
                        // Create an area name entry for each of them. 
                        $updateState = true;

                        // Update area name state
                        $AreaNameState[$areaNameAttrIndex] = $csv[$attrIndex];
                        // Prepare next area name
                        $areaName = [];
                        for ($j = 0; $j <= $areaNameAttrIndex; $j++) {
                            array_push($areaName, $AreaNameState[$j]);
                        }
                        for ($j = $areaNameAttrIndex + 1; $j < $areaNameAttrCount; $j++) {
                            array_push($areaName, 'X');
                        }
                        array_push($areaName, $csv[$attrIndex + 1]);
                        array_push($areaNames, $areaName);
                    }
                }
            }
        }
        $logger->debug("$parsedLines geocode lines parsed ($duplicateLines duplicates ignored)");
        $linesProcessedCount = $parsedLines + $duplicateLines;

        return $areaNames;
    }

    private function CreateKey($csv) {
        $key = "";
        $attrCount = is_countable($csv) ? count($csv) : 0;

        for ($attrIndex = 0; $attrIndex < $attrCount; $attrIndex++) {
            if ($attrIndex % 2 === 0) {
                // Concatenate geocodes to create unique key
                $key .= "$csv[$attrIndex]";
            }
        }

        return $key;
    }

}
