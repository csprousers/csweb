<?php
namespace AppBundle\CSPro;
class VectorClock
{
    // device revision map
    private $deviceRevMap;
	
	
	//constructor that takes vector string and creates the map.
	function __construct($jsonClockArray) {
		if(!isset($jsonClockArray)){
			$this->deviceRevMap = [];
		}
		else{
			//preg_match_all("/([^,: ]+)=([^,: ]+)/", $strVectorClock, $r);
			$this->deviceRevMap = [];
			foreach ($jsonClockArray  as $row) {
				$this->deviceRevMap[$row['deviceId']] = $row['revision'];
			}
		}
	}

	//get comma delimited deviceId:revision JSON string
	public function getJSONClockString(){
		$jsonArray = [];
		$i=0;
		foreach ($this->deviceRevMap as $dev => $version) {
			//force deviceId to string for the conversion in case the deviceid is all numeric
			//otherwise json_encode does not quote the deviceId -vietnam bug
			//php array map cannot have numeric string as key hence the problem
			$row['deviceId'] =  (string)$dev; 
			$row['revision'] =  $version;
			$jsonArray[$i] = $row;
			$i++;
		}
		return json_encode($jsonArray, JSON_THROW_ON_ERROR);
	}
    // Get version for specific device
    public function getVersion($deviceId) {
		if(isset($this->deviceRevMap[$deviceId])){
			return $this->deviceRevMap[$deviceId];
		}
		else {
			return 0;
		}
    }
	
	// Set specific revision
	public function setVersion($deviceId, $version){
		$this->deviceRevMap[$deviceId] = $version;
	}
	// Increment version number for specific device
	public function increment($deviceId){
		if(isset($this->deviceRevMap[$deviceId])){
			$this->deviceRevMap[$deviceId] += 1;
		}
		else {
			$this->deviceRevMap[$deviceId] = 1;
		}
	}
	
	// Combine two vector clocks by taking max of versions for each device
	public function merge($rhsVectorClock)
	{
		foreach ($rhsVectorClock->deviceRevMap as $dev => $version) {
			if(!isset($this->deviceRevMap[$dev])){
				$this->deviceRevMap[$dev] = $version;
			}
			else{
				$this->deviceRevMap[$dev] = max($this->deviceRevMap[$dev],$version);
			}
		}
	}
	// Compare clocks - clock A is = B iff all versions are == corresponding
	// version in B
	public function IsEqual($rhsVectorClock){
		foreach ($this->deviceRevMap as $dev => $version) {
			if ($version != $rhsVectorClock->getVersion($dev))
				return false;
		}
		foreach ($rhsVectorClock->deviceRevMap as $dev => $version) {
			if ($version != $this->getVersion($dev))
				return false;
		}
		return true;
	}
	
	public function IsLessThan($rhsVectorClock){
	
		// Vector clock A is strictly less than B if all versions
		// in A are less than or equal to those in B and at least
		// one is strictly less.
		$foundStrict = false;
		foreach ($this->deviceRevMap as $dev => $version) {
			$myVersion = $version;
			$rhsVersion = $rhsVectorClock->getVersion($dev);
			if ($rhsVersion < $myVersion)
				return false;
			if ($rhsVersion > $myVersion)
				 $foundStrict = true;
		}
		// If we haven't found version that is strictly less then we need to look
		// for devs in rhs that are not in this. For example:
		//   {a:2, b:1} < {a:2, b:1, c:1}
		// where looking at devs in this only we would assume vectors are equal but
		// looking at c in rhs we can conclude that this < rhs
		foreach ($rhsVectorClock->deviceRevMap as $dev => $version) {
			if ($this->getVersion($dev) == 0){
				$foundStrict = true;
				break;
			}
		}
		return $foundStrict;
	}
	//compare vector clocks.  0- if the equal, <0 if  less than rhsVectorClock, > 0 if greater than rhsVectorClock
	public function compare($rhsVectorClock){
		if($this->IsEqual($rhsVectorClock)){
			return 0;
		}
		else if(IsLessThan($rhsVectorClock)){
			return -1;
		}
		return 1; //if not lhs > rhs
	}
	// Get all devices in the clock
	public function getAllDevices() {
		return array_keys($this->deviceRevMap);
	}
}
?>