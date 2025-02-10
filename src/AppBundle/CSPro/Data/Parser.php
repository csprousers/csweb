<?php
namespace AppBundle\CSPro\Data;
use AppBundle\CSPro\Dictionary;

/**
* Parse CSPro data in text format.
*
* Currently only one-level dictionaries are supported.
*/
class Parser
{

	/** @var Dictionary CSPro data dictionary */
	private $dict;
	
	/** @var string[] Raw data in CSPro text format that matches dictionary */
	private array $data;
	
	/**
    * Create from dictionary and text data.
	*
	* @param Dictionary $dict 		CSPro dictionary
	* @param string 	$caseData	Data for case in CSPro text format
	*/
	public function __construct($dict, $caseData)
	{
		$this->dict = $dict;
		$this->data = explode("\n", $caseData);
	}
	
	/**
	* Get value of item from case
	*
	* Extracts the value from the raw case data based on the items start
	* position and length in the dictionary.
	*
	* The value is converted to the type specified for the item in the 
	* dictionary: int or float for numeric items, string for alpha items.
	* Numeric items that are NOTAPPL will have a value of null.
	* If the item type is numeric and the value in the case data cannot
	* be converted to a number (what would be DEFAULT in CSPro) 
	* an exception is thrown.
	* 
	* For items on repeating records and items that repeat the
	* record and item occurrence numbers should be specified.
	* These are one based to match the behavior in CSPro so the
	* first occurrence is one, the second is 2...
	* If a record occurence number is specified and that occurence
	* does not exist in the data an exception is thrown.
	*
	* @param string $itemName Name of item in dictionary
	* @param string $recordOcc One based occurrence of record for repeating records
	* @param string $itemOcc One based occurrence of item for repeating items
	* @return mixed Value of item as dictionary type or null for notappl
	*/
	public function getItemValue($itemName, $recordOcc = 1, $itemOcc = 1)
	{
		[$item, $record] = $this->dict->findItem($itemName);
		if ($item == false)
			throw new \Exception('Item  ' . $itemName . ' not found in dictionary.');

		if ($record != null)
			$recordData = $this->getRecordData($record, $recordOcc);
		else
			// Null record means this is an id item so we can use any record
			$recordData = $this->data[0]; 
		
		return $this->extractItem($recordData, $item, $itemOcc);
	}

	/**
	* Get the number of actual occurrences of a record
	* in the case data.
	*
	* @param string Name of record in dictionary
	* @return int Number of occurrences of record
	*/
	public function getRecordOccurrences($recordName)
	{
		$record = $this->dict->findRecord($recordName);
		if ($record == false)
			throw new \Exception('Record  ' . $recordName . ' not found in dictionary.');
		$records = $this->getAllRecordData($record);
		return count($records);
	}
	
	/**
	* Get data for all records of a given type.
	*
	* @param Record $record Record type
	* @return string[] Raw data for each record that matches type
	*/
	private function getAllRecordData($record)
	{
		$recTypeValue = $record->getTypeValue();
		if (strlen($recTypeValue) == 0) {
			// No record type - get all records
			return $this->data;
		} else {
			// Get only records that match type
			return array_filter($this->data, fn($r) => $recTypeValue == $this->extractRecordType($r));
		}
	}
	
	/**
	* Get data for a record of a given type by occurence number.
	*
	* @param Record $record Record type
	* @param int $recordOcc Index of record
	* @return string[] Raw data for each record that matches type
	*/
	private function getRecordData($record, $recordOcc)
	{
		$records = $this->getAllRecordData($record);
		if (count($records) == 0)
			throw new \Exception('No occurrences of record '.$record->getName(). ' in case.');
		if ($recordOcc > count($records))
			throw new \Exception('Invalid record occurrence ' . $recordOcc . ' for record '.$record->getName(). '. There are only ' . count($records). ' occurrences in case.');
		if ($recordOcc < 1)
			throw new \Exception('Invalid record occurrence ' . $recordOcc . ' for record '.$record->getName(). '. Must be greater than or equal to 1.');
		return $records[$recordOcc - 1];
	}
	
	/**
	* Get the record type from a line of raw case data
	*
	* @param string $recordData Raw data for record
	* @return string Record type
	*/
	private function extractRecordType($recordData)
	{
		return substr($recordData, $this->dict->getRecordTypeStart() - 1, $this->dict->getRecordTypeLength());
	}
	
	/**
	* Extract value of dictionary item from record data.
	*
	* @param string $recordData Raw data for record
	* @param Item $item Dictionary item
	* @param int $itemOcc Occurrence number of item for repeating items
	* @return mixed Value of item
	*/
	private function extractItem($recordData, $item, $itemOcc)
	{
		if ($itemOcc < 1)
			throw new \Exception('Invalid item occurrence ' . $itemOcc . ' for item '.$item->getName(). '. Must be greater than or equal to 1.');
		
		$start = $item->getStart() - 1;
		if ($itemOcc > 1) {
			
			if ($item->getOccurrences() == 1 && $item->getItemType() == 'SubItem') {
				// Occurences are on the parent item
				$repeatingItem = $this->dict->findSubitemParent($item->getName());
				if ($repeatingItem == false)
					throw new \Exception('Invalid item occurrences ' . $itemOcc . ' for item '. $item->getName(). '. This is not a repeating item.');
			} else {
				$repeatingItem = $item;
			}
			
			if ($itemOcc > $repeatingItem->getOccurrences())
				throw new \Exception('Invalid item occurrences ' . $itemOcc . ' for item '. $item->getName(). '. Max occurrence is ' . $repeatingItem->getOccurrences());
			
			$start += ($itemOcc - 1) * $repeatingItem->getLength();
		}
		$rawVal = substr($recordData, $start, $item->getLength());
		if ($rawVal == false) {
			// we are past the end of the record, we should treat it as blank
			$rawVal = str_repeat(" ", $item->getLength());
		}
		
		return $this->convertRawValToType($rawVal, $item);
	}
	
	/**
	* Convert raw value from text data to appropriate PHP type
	* based on dictionary item type.
	*
	* @param string $rawVal String value of item from raw case data
	* @param Item $item Dictionary item
	* @return mixed Value of item as appropriate PHP type.
	*/
	private function convertRawValToType($rawVal, $item)
	{
		if ($item->isNumeric()) {
			// If all blank then this is NOTAPPL which we will make null
			if (strlen(trim($rawVal)) == 0)
				return null;

			// Convert default values to null since there is no simple way
			// to represent default in numeric database column
			if ($this->isDefault($rawVal))
				return null;
			
			if (!is_numeric($rawVal)) {
				throw new \Exception('Invalid data for numeric item '. $item->getName(). ': ' . $rawVal);
			}

			if ($item->getDecimalPlaces() == 0) {
				// No decimal places - this is an integer
				return intval($rawVal);
			} else {
				// Decimal places - this is a float
				if ($item->getDecimalChar()) {
					return floatval($rawVal);
				} else {
					// No decimal char in raw data so we need to insert one before converting
					return floatval(substr($rawVal, 0, strlen($rawVal) - $item->getDecimalPlaces()).'.'.substr($rawVal, strlen($rawVal) - $item->getDecimalPlaces()));
				}
			}
		} else {
			// Alpha value is already a string but we may need to pad the end
			if (strlen($rawVal) < $item->getLength())
				$rawVal .= str_repeat(" ", $item->getLength() - strlen($rawVal));
			return $rawVal;
		}		
	}

	/**
	* Check if value is CSPro default (all *).
	*
	* @param string $rawVal String value of item from raw case data
	* @return bool True if item is default.
	*/
	private function isDefault($rawVal)
	{
		return preg_match('/^\*+$/', $rawVal);
	}
};