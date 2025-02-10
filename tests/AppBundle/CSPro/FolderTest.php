<?php
namespace CSPro\Tests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FolderTest extends WebTestCase
{
	private static $rootFolder;
	private  static  $accessToken;
	const FILEPATH= '/testUploadDir/';
	
	public static function setUpBeforeClass()
	{
	}
	public static function tearDownAfterClass()
	{
		//remove the testUploadDir
		if(is_dir(realpath(self::$rootFolder . self::FILEPATH)))
			rmdir (realpath(self::$rootFolder . self::FILEPATH));
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
					array('CONTENT_TYPE' => 'application/json',
							'HTTP_AUTHORIZATION' => self::$accessToken),
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
		self::$rootFolder = $app['files_folder'];
		
		//create the upload folder		
		if(!is_dir(realpath(self::$rootFolder . self::FILEPATH)))
			mkdir(realpath(self::$rootFolder . self::FILEPATH));

		unset($app['exception_handler']);
		
		return $app;
	}
	public function testFolderGet()
	{
		$client = static::createClient();
		//test file not found case
		$crawler = $client->request('GET', '/folders/foldernotfound',
												array(),
												array(),
												array('HTTP_AUTHORIZATION' => self::$accessToken)
												);
		$this->assertJsonStringEqualsJsonString('{"type":"error","status":404,"code":"directory_not_found","message":"Directory not found"}',
											$client->getResponse()->getContent(),
											'Invalid response for folder not found case'
											);
		//test  empty folder  get
		$crawler = $client->request('GET', '/folders'. '/testUploadDir',
												array(),
												array(),
												array('HTTP_AUTHORIZATION' => self::$accessToken)
												);
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		
		$this->assertJsonStringEqualsJsonString('[]',
												$client->getResponse()->getContent(),
												'Invalid fileinfo for directory'
												);
		//test non empty folder get 
		$crawler = $client->request('GET', '/folders'. '/',
												array(),
												array(),
												array('HTTP_AUTHORIZATION' => self::$accessToken)
												);
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		
		$this->assertContains('testUploadDir',$client->getResponse()->getContent(), 'invalid directory list ' . $client->getResponse()->getContent());
	}
}