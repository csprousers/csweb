<?php
namespace CSPro\Tests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FilesTest extends WebTestCase
{
	private static  $rootFolder ;
	const TESTFILENAME = 'test.png';
	const FILEPATH= 'testUploadDir/';
	
	private  static  $accessToken;
	public static function setUpBeforeClass()
	{

	}
	public static function tearDownAfterClass()
	{
		//remove the testUploadDir
		unlink( realpath(self::$rootFolder .'/'. self::FILEPATH . self::TESTFILENAME));
		if(is_dir(self::$rootFolder . self::FILEPATH))
			rmdir (self::$rootFolder . self::FILEPATH);
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
		self::$rootFolder =  $app['files_folder'];
	
		return $app;
	}
	
	public function testFilePut()
	{
		$client = static::createClient();	

		$testFile =  dirname ( __FILE__ ) . '/' . self::TESTFILENAME;
		
		$this->assertFileExists($testFile, 'Missing file ' . $testFile);

		$content = file_get_contents($testFile);
		$md5File = md5_file($testFile);
		$crawler = $client->request('PUT',
									'/files/'. self::FILEPATH . self::TESTFILENAME .'/content', 
									array(),
									array(),
									array('CONTENT_TYPE' => 'application/json',
									'CONTENT_MD5' => $md5File,
									'HTTP_AUTHORIZATION' => self::$accessToken), 
									$content);
		$this->assertEquals(md5($content), $md5File,'invalid download file contents');

		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);

		$this->assertJsonStringEqualsJsonString('{"type":"file","name":"test.png","directory":"testUploadDir","md5":"b6957ef3e3ea537fcfe73c0737413372","size":10088}',
												$client->getResponse()->getContent(),
												'Invalid fileino  for file upload: ' . $client->getResponse()->getContent()
												);
		
		//upload file without content.
		$crawler = $client->request('PUT',
									'/files/'. self::FILEPATH . self::TESTFILENAME .'/content', 
									array(),
									array(),
									array('CONTENT_TYPE' => 'application/json',
									'CONTENT_MD5' => $md5File,
									'HTTP_AUTHORIZATION' => self::$accessToken)
									);

		$this->assertEquals(403, 
							$client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		
		$this->assertJsonStringEqualsJsonString('{"type":"error","status":403,"code":"file_save_failed","message":"Unable to write to filePath. Content length or md5 does not match uploaded file contents or md5."}',
												$client->getResponse()->getContent(),
												'Invalid fileino  for file upload: ' . $client->getResponse()->getContent()
												);
	}
	public function testFileGetContent()
	{
		$client = static::createClient();
		//test file not found case
		$crawler = $client->request('GET','/files/'. self::FILEPATH . 'filenotfound' .'/content',
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertEquals(404, 
							$client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);

		$this->assertJsonStringEqualsJsonString('{"type":"error","status":404,"code":"file_not_found","message":"File not found"}',
												$client->getResponse()->getContent(),
												'Invalid fileinfo for file not found case'
												);
		//Get TESTFILE
		$crawler = $client->request('GET','/files/'. self::FILEPATH . self::TESTFILENAME .'/content'	,
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		// Enable the output buffer
		ob_start();
		// Send the response to the output buffer
		$client->getResponse()->sendContent();
		// Get the output buffer and clean it
		$content = ob_get_contents();
		// Clean the output buffer and end it
		ob_end_clean();
		$this->assertEquals(200, $client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
		
		$etag = $client->getResponse()->headers->get('Etag');
		
		$this->assertEquals(md5($content), $etag, 'incorrect file downloaded');

		
		$attachmentName = $client->getResponse()->headers->get('Content-Disposition');
		$attachmentName = str_replace('"' , '' , substr($attachmentName, strpos($attachmentName,'=')+1));
		$this->assertEquals($attachmentName, 
							self::TESTFILENAME,
							'invalid attachment name ' .$attachmentName
							);
		//Get with ETag
		$crawler = $client->request('GET', 
									'/files/'. self::FILEPATH . self::TESTFILENAME . '/content',
									array(),
									array(),
									array('HTTP_IF_NONE_MATCH' => $etag,
											'HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertEquals(304, 
							$client->getResponse()->getStatusCode(),
							'invalid status code ' . $client->getResponse()->getStatusCode()
							);
	}
	public function testFileGet()
	{
		$client = static::createClient();
		//test root directory get
		$crawler = $client->request('GET', '/files/',
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);

		$this->assertEquals(
		200, 
		$client->getResponse()->getStatusCode(),
		'invalid status code ' . $client->getResponse()->getStatusCode()
		);
		
		$this->assertJsonStringEqualsJsonString('{"type":"directory","name":"","directory":""}',
												$client->getResponse()->getContent(),
												'Invalid fileinfo for directory'
												);
		//test file not found case
		$crawler = $client->request('GET', '/files/filenotfound',	
									array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertJsonStringEqualsJsonString('{"type":"error","status":404,"code":"file_not_found","message":"File not found"}',
											$client->getResponse()->getContent(),
											'Invalid fileinfo for file not found case'
											);
		//test.png file
		$crawler = $client->request('GET', '/files/'. self::FILEPATH . self::TESTFILENAME, array(),
									array(),
									array('HTTP_AUTHORIZATION' => self::$accessToken)
									);
		$this->assertEquals(
		200, 
		$client->getResponse()->getStatusCode(),
		'invalid status code ' . $client->getResponse()->getStatusCode()
		);
		$this->assertJsonStringEqualsJsonString('{"type":"file","name":"test.png","directory":"testUploadDir","md5":"b6957ef3e3ea537fcfe73c0737413372","size":10088}',
												$client->getResponse()->getContent(),
												'Response content does not match expected JSON object in Get file request'
												);
		
		
	}
	
}

?>