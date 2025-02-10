<?php
namespace CSPro\Tests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SyncTest extends WebTestCase
{
	public function createApplication()
    {
		$app_env = 'test';
		$app = require __DIR__ . '/../../../../../api/index.php';
		$app['debug'] = true;
		unset($app['exception_handler']);

		return $app;
    }

    public function testServer()
    {
       $client = static::createClient();
       $crawler = $client->request('GET', '/server');

	   $this->assertEquals(
			200, 
			$client->getResponse()->getStatusCode()
		);
	
		   // Assert that the "Content-Type" header is "application/json"
		$this->assertTrue(
			$client->getResponse()->headers->contains(
				'Content-Type',
				'application/json'
			)
		);
	   $jsonResponse = json_decode($client->getResponse()->getContent());
       $this->assertEquals(
            'server',
            $jsonResponse->deviceId,
			'Server name ' .  $jsonResponse->deviceId .'is not incorrect'
        );
    }
	/*public function testSync()
    {
		//Delete the cases is they already exist. 
		//verify sync cases do not exist 
		//Sync cases Initial step 
		//Sync cases Step 2. 
		//Sync Cases Step 3. 
		//Verify clocks .
	}*/
}

?>