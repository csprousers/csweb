<?php

use PHPUnit\Framework\TestCase;
use AppBundle\CSPro\Dictionary\Dictionary;
use AppBundle\CSPro\Dictionary\JsonDictionaryParser;
use AppBundle\CSPro\Dictionary\DictionaryKeys;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of DictionaryTest
 *
 * @author savy
 */
class DictionaryTest extends TestCase {

    //put your code here
    //read a dictionary from disc
    //parse the dictionary
    //assert that there is no error 
    //validate json dictionary read and the objects mapped
    const TESTFILENAME = 'NHIES_DAILY_BOOK 01.dcf';
    //const TESTFILENAME = 'Household.dcf';
    const FILEPATH = '/test_files/';

    public function testDictionaryRead(): void {
//        $testFile = 'file://' . realpath(__DIR__ . self::FILEPATH . self::TESTFILENAME);

        $di = new RecursiveDirectoryIterator(__DIR__ . self::FILEPATH, RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new RecursiveIteratorIterator($di);

        foreach ($it as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) == "dcf") {
//                if (!str_contains($file, "IARecode7.dcf")) {
//                    continue;
//                }

                echo $file, PHP_EOL;
                ob_flush();
                $testFile = $file;
                $this->assertFileExists($testFile, 'Missing file ' . $testFile);
                $jsonDictionaryContent = file_get_contents($testFile);
                try {
                    $jsonDictionaryParser = new JsonDictionaryParser();
                    $dict = $jsonDictionaryParser->parseDictionary($jsonDictionaryContent);
                    $jsonDict = $jsonDictionaryParser->getJSONDictionary();
                    $this->assertNotNull($dict);
                    $this->assertInstanceOf(Dictionary::class, $dict);
                    $this->assertTrue($this->validateDictionary($dict, $jsonDictionaryContent), 'Dictionary validation failed');
                } catch (\Exception $ex) {
                    echo $testFile, PHP_EOL;
                    ob_flush();
                    $this->assertFalse(true, "Error: " . $ex->getMessage());
                }
                ob_flush();
            }
        }
    }

    private function recursive_array_diff($a1, $a2): array {
        $r = array();
        foreach ($a1 as $k => $v) {
            if (array_key_exists($k, $a2)) {
                if (is_array($v)) {
                    $rad = $this->recursive_array_diff($v, $a2[$k]);
                    if (count($rad)) {
                        $r[$k] = $rad;
                    }
                } else {
                    if ($v != $a2[$k]) {
                        $r[$k] = $v;
                    }
                }
            } else {
                $r[$k] = $v;
            }
        }
        return $r;
    }

    private function validateDictionary(Dictionary $dictionary, string $jsonDictionary): bool {
        $result = true;

        $jsonDictionaryFromObj = json_encode($dictionary->toArray(null)); //convert dictionary object to associative array and encode it as json string
        $jsonFromObject = json_decode($jsonDictionaryFromObj, true); //decode the json dictionary string to associative array

        //decode the json dictionary file content string to associative array
        $jsonDictionaryFromFile = json_decode($jsonDictionary, true);

        //for each json key in the file, check existance of the key in the in memory ready jsonobject to validate if the read was done correctly
        $difference = $this->recursive_array_diff($jsonDictionaryFromFile, $jsonFromObject);
        if (count($difference) > 0)
            print_r($difference);
        $this->assertTrue(empty($difference));

        ob_flush();
        return $result;
    }

}
