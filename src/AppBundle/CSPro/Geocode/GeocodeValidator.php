<?php

namespace AppBundle\CSPro\Geocode;

class GeocodeValidator {

    private array $errors = [];

    public function isValid() {
        return count($this->errors) === 0;
    }

    public function getErrors() {
        return $this->errors;
    }

    public function reset() {
        $this->errors = [];
    }

    public function addError($errMsg) {
        array_push($this->errors, $errMsg);
    }

    // Validate geocode fields
    public function validate($csv, $attrCount, $displayLineNumber) {
        $errMsg = "";
        $errBlank = "";
        $errNotNumeric = "";

        for ($attrIndex = 0; $attrIndex < $attrCount; $attrIndex++) {
            $displayFieldNumber = $attrIndex + 1;
            if ($csv[$attrIndex] === "") {
                $errBlank .= $errBlank === "" ? $displayFieldNumber : ", $displayFieldNumber";
            }
            else if ($attrIndex % 2 === 0) {
                // Even attribute
                if (!ctype_digit($csv[$attrIndex])) {
                    $errNotNumeric .= $errNotNumeric === "" ? $displayFieldNumber : ", $displayFieldNumber";
                }
            }
        }

        $errMsg .= $errBlank === "" ? "" : "Field $errBlank cannot be blank. ";
        $errMsg .= $errNotNumeric === "" ? "" : "Field $errNotNumeric must be numeric. ";

        if ($errMsg !== "") {
            $errMsg = "Error (line $displayLineNumber): $errMsg";
            $this->addError($errMsg);
        }

        return $errMsg === "";
    }

    public function validateImportGeocodes($content, $headerRow, $logger) {
        $this->reset();

        // An empty CSV is only a line of "\r\n." The preg_replace converts it to an empty string.
        $content = preg_replace('/^[ \t]*[\r\n]+/m', '', $content);
        // array_filter is used to filter out a length 0 array created from the empty string
        //    * An empty CSV is now an empty string. There is no match for "\n," so the empty string is returned.
        //    * A CSV with data will end with "\r\n." The explode generates an empty string at the final delimiter.
        $lines = array_filter(explode("\n", $content), 'strlen');
        $lineCount = count($lines);

        if ($this->IsEmptyImportFile($headerRow, $lineCount)) {
            $this->addError('Import file is empty');
            return false;
        }

        $startLine = 0;
        if ($headerRow === true) {
            $logger->debug('Header ignored by validator');
            $startLine = 1;
        }

        // Determine attribute count of geocode data
        $attrCount = count(str_getcsv($lines[$startLine], ',', '"', "\n"));

        if ($attrCount % 2 !== 0) {
            $this->addError("Error: Each row must consist of code and label pairs. There is an odd number of fields.");
        }
        else {
            for ($i = $startLine; $i < $lineCount; $i++) {
                $csv = str_getcsv($lines[$i], ',', '"', "\n");

                $displayLineNumber = $i + 1;
                $this->validate($csv, $attrCount, $displayLineNumber);
            }
            $validatedLinesCount = $lineCount - $startLine;
            $logger->debug("$validatedLinesCount geocode lines validated");
        }

        $isValid = $this->isValid();
        return $isValid;
    }

    private function IsEmptyImportFile($headerRow, $lineCount) {
        $isEmpty = false;
    
        if ($lineCount === 0 or ($headerRow === true and $lineCount === 1)) {
            $isEmpty = true;
        }
    
        return $isEmpty;
    }

}
