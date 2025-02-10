<?php
namespace CSPro\Tests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserTest extends WebTestCase
{
	private  static  $accessToken;
	public static function setUpBeforeClass()
	{
	}
	public static function tearDownAfterClass()
	{
	}
	public function setUp()
	{
		 parent::setUp();
		if(!isset(self::$accessToken)){
				$client = static::createClient();	

				$crawler =$client->request(
					'POST',
					'/token',
					array(),
					array(),
					array('CONTENT_TYPE' => 'application/json'),
					'{"client_id":"cspro_android","client_secret":"cspro","grant_type":"password","username":"savy","password":"savypwd"}'
				);
				
				$data = json_decode($client->getResponse()->getContent(),true);
				self::$accessToken = 'Bearer ' .  $data['access_token'];
			}
	}
	public function createApplication()
	{
		$app_env = 'test';
		$app = require __DIR__ . '/../../../../../api/index.php';
		$app['debug'] = true;
		unset($app['exception_handler']);
		
		return $app;
	}
	public function testUserGet()
	{
		$client = static::createClient();
		//Get User not found
		$crawler = $client->request('GET', '/users/usernotfound'	,
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertJsonStringEqualsJsonString('{"type":"error","status":404,"code":"user_not_found","message":"User not found"}',
											$client->getResponse()->getContent(),
											'Invalid response for user not found case'
											);
											
	    //Get user savy 
		$crawler = $client->request('GET', '/users/savy',
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		$this->assertJsonStringEqualsJsonString('{"username":"savy","firstName":"Savys","lastName":"Surumpudi", "role":"1"}',
											$client->getResponse()->getContent(),
											'JSON response does not match the expected output for user savy'
											);
		//Get users 
		$crawler = $client->request('GET', '/users/',
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
	}
	public function testUserPut()
	{
		//modify user usernotfound
		$client = static::createClient();
		$crawler = $client->request('PUT', '/users/usernotfound',
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken),
									'{"username":"usernotfound","firstName":"first","lastName":"last","email":"test@gmail.com","password":"testpwd", "role":"1"}');
		$this->assertEquals(404, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		//modify user savy
		$crawler = $client->request('PUT', '/users/savy',
									array(),
									array(),
									array('CONTENT_TYPE' => 'application/json',
											'HTTP_AUTHORIZATION' => self::$accessToken),
									'{"username":"savy","firstName":"Savys","lastName":"Surumpudi","password":"savypwd", "role":"1"}');
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'modify user- invalid status code: ' . $client->getResponse()->getStatusCode()
							);
	}
	public function testUserDelete()
	{
		//delete user savy??
	}
}