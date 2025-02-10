<?php
//require_once __DIR__ . '/../src/vectorclock.php';
namespace CSPro\Tests;
use CSPro\VectorClock;
use PHPUnit\Framework\TestCase;
class VectorClockTest extends TestCase
{
    // ...

    public function testIncrement()
    {
		$clock = new VectorClock(null);
		$this->assertEquals(0,$clock->getVersion('A'), 'New clock does not have version 0');
		$clock->increment('A');
		$this->assertEquals(1,$clock->getVersion('A'), 'First increment not equal 1');
		$clock->increment('A');
		$this->assertEquals(2,$clock->getVersion('A'), 'Second increment not equal 2');
    }
	
	public function testCopy()
	{
			$clock = new VectorClock(null);
			$clock->increment('A');
			$clock->increment('A');
			$clock->increment('B');
			$jsonArray  =json_decode($clock->getJSONClockString(),true);
			$clock2 = new VectorClock($jsonArray);
			$this->assertEquals(2,$clock2->getVersion('A'), 'Copied clock A not 2');
			$this->assertEquals(1, $clock2->getVersion('B'), 'Copied clock B not 1');
			$this->assertEquals(0, $clock2->getVersion('C'), 'Copied clock C not 0');
	}

	public function testCompareEmpty()
		{
			
			$clock = new VectorClock(null);
			$clock->increment('A');
			$clock->increment('A');
			$clock->increment('B');
			
			$emptyClock = new VectorClock(null);

			$this->assertTrue($emptyClock->IsLessThan($clock), 'Empty clock not < non empty clock');
			$this->assertFalse($clock->IsLessThan($emptyClock), 'non empty clock < emptyClock');
		}
		
	public function testCompareDescendant()
		{
			$parent = new VectorClock(null);
			$parent->increment('A');
			$parent->increment('A');
			$parent->increment('B');

			$jsonArray  =json_decode($parent->getJSONClockString(),true);
			$child = new VectorClock($jsonArray);
			$child->increment('C');

			$this->assertTrue($parent->IsLessThan($child), 'parent not < child');
			$this->assertFalse($child->IsLessThan($parent), 'Child < parent');
		}
	public function testCompareNewDescendant()
		{
			$parent = new VectorClock(null);
			$parent->increment('A');
			$parent->increment('A');
			$parent->increment('B');

			$jsonArray  =json_decode($parent->getJSONClockString(),true);
			$child = new VectorClock($jsonArray);
			$child->increment('A');

			$this->assertTrue($parent->IsLessThan($child), 'parent not < child');
			$this->assertFalse($child->IsLessThan($parent), 'Child < parent');
		}
		
	public function testCompareEquals()
		{
			$clock = new VectorClock(null);
			$clock->increment('A');
			$clock->increment('A');
			$clock->increment('B');

			$jsonArray  =json_decode($clock->getJSONClockString(),true);
			$clock2 = new VectorClock($jsonArray);

			$this->assertFalse($clock->IsLessThan($clock2), 'Equal vectors are <');
			$this->assertFalse($clock2->IsLessThan($clock), 'Equal vectors are <');
		}
	public function testCompareDisJoint()
		{
			$clock = new VectorClock(null);
			$clock->increment('A');
			$clock->increment('A');
			$clock->increment('B');

			$clock2 = new VectorClock(null);
			$clock2->increment('C');
			$clock2->increment('D');

			$this->assertFalse($clock->IsLessThan($clock2), 'Disjoint vectors are <');
			$this->assertFalse($clock2->IsLessThan($clock), 'Disjoint vectors are <');
		}
    public function testCompareConflicting()
		{
			$clock = new VectorClock(null);
			$clock->increment('A');
			$clock->increment('A');
			$clock->increment('B');

			$jsonArray  =json_decode($clock->getJSONClockString(),true);
			$clock2 = new VectorClock($jsonArray); //copy clock to clock2
			$clock2->increment('B');
			
			$clock->increment('A');
			
			$this->assertFalse($clock->IsLessThan($clock2), 'Conflicting vectors are <');
			$this->assertFalse($clock2->IsLessThan($clock), 'Conflicting vectors are <');
		}
}
