<?php

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

namespace AppBundle\CSPro\Data;

use AppBundle\CSPro\Data\CSProResourceBuffer;
use Psr\Log\LoggerInterface;

class BinaryJSONConverter {

    public const BINARY_CASE_HEADER = "cssync";
    const VERSION = 1;

    public function __construct(private LoggerInterface $logger) {
        
    }

    public static function IsBinaryJSON(string $signature): bool {
        return strcmp($signature, self::BINARY_CASE_HEADER) == 0;
    }

    public function readBinaryJsonHeader(CSProResourceBuffer $inputStream, int &$version) {
        $signature = $inputStream->getString(strlen(self::BINARY_CASE_HEADER));
        $this->logger->debug("binary JSON header signature $signature");
        if (strcmp($signature, self::BINARY_CASE_HEADER) !== 0) {
            throw new Exception('Invalid binary JSON');
        }
        //read version number
        $version = $inputStream->getInt();
        return;
    }

    public function readCasesJsonFromBinaryToStream(CSProResourceBuffer $inputResourceBuffer, $outputStream): int {
        $this->logger->debug("Reading cases json from binary format");
        $version = -1;
        //read the binary json header
        $this->readBinaryJsonHeader($inputResourceBuffer, $version);
        $this->logger->debug("Binary JSON format version is $version");
        //read the questionnaire json size
        $questionnaireSize = $inputResourceBuffer->getInt();
        $returnValue = $inputResourceBuffer->copyToStream($outputStream, $questionnaireSize);
        rewind($outputStream);
        return $returnValue;
    }

    public function readCasesJsonFromBinary(CSProResourceBuffer $inputStream): string {
        $this->logger->debug("Reading cases json from binary format");
        $inputStream->rewind();
        $version = null;
        //read the binary json header
        $this->readBinaryJsonHeader($inputStream, $version);
        $this->logger->debug("Binary JSON format version is $version");
        //read the questionnaire json size
        $questionnaireSize = $inputStream->getInt();
        $casesJson = $inputStream->getString($questionnaireSize);

        return $casesJson;
    }

    public function readBinaryCaseItemsNSave(CSProResourceBuffer $inputResourceBuffer, $binaryContentFolderPath): array {
        $caseBinaryItems = array();
        while ($inputResourceBuffer->hasRemaining()) {
            $binaryItemJSONSize = $inputResourceBuffer->getInt();
            if ($binaryItemJSONSize > 0) {
                $binaryItemJSON = $inputResourceBuffer->getString($binaryItemJSONSize);
                $binaryCaseItem = json_decode($binaryItemJSON, true);
                $signature = $binaryCaseItem['signature'];
                $caseBinaryItems[] = $signature;
                $binaryContentLength = $inputResourceBuffer->getInt();
                if ($binaryContentLength >= 0) {
                    //read the binary content 
                    $fileName = rtrim($binaryContentFolderPath, '\\/') . DIRECTORY_SEPARATOR . $signature;
                    $outputStream = fopen($fileName, 'w+');
                    if ($binaryContentLength > 0) {
                        $inputResourceBuffer->copyToStream($outputStream, $binaryContentLength);
                    }
                    fclose($outputStream);
                }
            }
        }
        return $caseBinaryItems;
    }

    public function writeBinaryJsonHeader(CSProResourceBuffer $outputStream) {
        //write signature bytes 
        $outputStream->insertString(self::BINARY_CASE_HEADER);
        //version number
        $outputStream->insertInt(self::VERSION);
    }

    /**
     * writes out the binary JSON format to the outputResourceBuffer 
     * @param CSProResourceBuffer $outputResourceBuffer
     * @param resource $casesJSONStream - input of all the cases JSON
     * @param type $caseBinaryItemMap - list of binary items to send out
     * @param type $binaryContentFolderPath - location of the binary items content on the disk
     * @throws Exception
     */
    public function writeBinaryJson(CSProResourceBuffer $outputResourceBuffer, $casesJSONStream, $caseBinaryItemMap, $binaryContentFolderPath) {
        //write header
        $this->writeBinaryJsonHeader($outputResourceBuffer);
        $fstats = fstat($casesJSONStream);
        //write cases (size of json and json string)
        if (is_array($fstats) && isset($fstats['size'])) {
            $outputResourceBuffer->insertInt($fstats['size']);
        } else {
            throw new Exception('Failed writing cases json');
        }
        //write the cases JSON
        $outputResourceBuffer->copyFromStream($casesJSONStream);
        //write the binary items 
        $this->writeBinaryItems($outputResourceBuffer, $caseBinaryItemMap, $binaryContentFolderPath);
    }

    public function writeBinaryItems(CSProResourceBuffer $outputResourceBuffer, $caseBinaryItemMap, $binaryContentFolderPath) {
        foreach ($caseBinaryItemMap as $key => $binaryItems) {
            foreach ($binaryItems as $binaryItem) {
                $signature = $binaryItem['signature'];
                $binaryJSON = json_encode($binaryItem);
                $outputResourceBuffer->insertInt(strlen($binaryJSON));
                $outputResourceBuffer->insertString($binaryJSON);
                $binaryFileName = $binaryContentFolderPath . DIRECTORY_SEPARATOR . $signature;
                $binaryFileStream = fopen($binaryFileName, "rb");
                if (!$binaryFileStream) {
                    $this->logger->error("Failed opening binary item file content for case $key with signature $signature");
                } else {
                    $fstats = fstat($binaryFileStream);
                    //write cases (size of json and json string)
                    if (is_array($fstats) && isset($fstats['size'])) {
                        $outputResourceBuffer->insertInt($fstats['size']);
                    } else {
                        $this->logger->error("Failed opening binary item file content for case $key with signature $signature");
                        throw new Exception("Failed opening binary item file content for case $key with signature $signature");
                    }
                    $outputResourceBuffer->copyFromStream($binaryFileStream);
                    fclose($binaryFileStream);
                }
            }
        }
    }

}
