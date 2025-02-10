<?php
namespace AppBundle\CSPro\FileManager;

use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use League\Flysystem\Adapter\Ftp as FtpAdapter;
use Google\Cloud\Storage\StorageClient;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use OpenCloud\OpenStack;
use OpenCloud\Rackspace;
use League\Flysystem\Rackspace\RackspaceAdapter;
use League\Flysystem\Sftp\SftpAdapter;

use AppBundle\CSPro\FileManager\Utils;


class FileInfo
{
	var $type;
	var	$name;
	var $directory;
	var $md5;
	var $size;
}

class FileManagerFlysystem
{
	public $rootFolder = null;

	public $adapter = 'local';

	private array $default_config = [
    	'adapter' => 'local',

	];

	private $filesystem = null;

	public function __construct($config = []){
    	// should this be a singleton?
        $this->config = array_merge($this->default_config, $config);
        $this->rootFolder =$this->config['rootFolder'];// array_get($this->config, 'rootFolder');
        if($this->rootFolder)
            $this->filesystem = new LeagueFilesystem($this->getAdapter($this->config['adapter']));
	}


	private function getAdapter($adapter_slug = null){
    	$adapter_key = $adapter_slug ?? $this->config['adapter'];
    	if($adapter_key == 'local') return new LocalAdapter($this->rootFolder);
    	else if($adapter_key == 's3') return new AwsS3Adapter($this->rootFolder);
    	else if($adapter_key == 'azure') return new AzureBlobStorageAdapter($this->rootFolder);
    	else if($adapter_key == 'rackspace') return new RackspaceAdapter($this->rootFolder);
    	else if($adapter_key == 'google') return new GoogleStorageAdapter($this->rootFolder);
    	else if($adapter_key == 'sftp') return new SftpAdapter($this->rootFolder);
    	else if($adapter_key == 'ftp') return new FtpAdapter($this->rootFolder);
    	else return new LocalAdapter($this->rootFolder);
	}

	public function getFilesystem(){
    	if(!$this->filesystem) $this->filesystem = new LeagueFilesystem($this->getAdapter($this->config['adapter']));
    	return $this->filesystem;
	}


	private function returnFileInfo($file_object){
        $filesystem = $this->getFilesystem();
		$fileInfo = new FileInfo();
		foreach($file_object as $k => $v)
		    $fileInfo->{$k} = $v;

	    if($fileInfo->type == 'dir') $fileInfo->type = 'directory';
		$fileInfo->name = $fileInfo->basename;
		$fileInfo->directory = empty($fileInfo->dirname) ? '/' : $fileInfo->dirname;
        if($fileInfo->type == 'file'){
            $fileInfo->md5 = @md5($filesystem->read($fileInfo->path));
        }
        else if($fileInfo->type == 'directory'){
            unset($fileInfo->md5);
            unset($fileInfo->size);
        }
		return $fileInfo;
	}


	public function getDirectoryListing($folderPath = '/'){
        $filesystem = $this->getFilesystem();

    	$file_listing = $filesystem->listContents($folderPath);
    	$file_listing = collect($file_listing)->map(fn($v) => $this->returnFileInfo($v));
        return $file_listing->toArray();
	}

	public function putFile($filePath,$content){
    	$filesystem = $this->getFilesystem();
    	$filesystem->put($filePath, $content);
    	$files = $this->getDirectoryListing(dirname($filePath));


    	return '';
	}
	public function getFileInfo($filePath){
		$folderPath = "";
		if(!isset($this->rootFolder))
			return null;

		$absfolderPath = $this->rootFolder;
		$pos = strrpos($filePath, '/');
		if($pos===FALSE){
			$fileName = $filePath;
		}
		else {
			$folderPath = substr($filePath,0,$pos);
			$fileName = substr($filePath,$pos+1);
			$absfolderPath = $this->rootFolder . DIRECTORY_SEPARATOR . $folderPath;
		}
		// Write the contents back to the file
		$file = $absfolderPath . DIRECTORY_SEPARATOR . $fileName;
		if( @is_file($file) === TRUE){
			$fileInfo = new FileInfo();
			$fileInfo->type = 'file';
			$fileInfo->name = $fileName;
			$fileInfo->md5 = @md5_file($file);
			$fileInfo->size = @filesize($file);
			$fileInfo->directory = $folderPath;
			return $fileInfo;
		}
		else if(is_dir($file)){
			$fileInfo = new FileInfo();
			$fileInfo->type = 'directory';
			$fileInfo->name = $fileName;
			$fileInfo->directory = $folderPath;
			unset($fileInfo->md5);
			unset($fileInfo->size);
			return $fileInfo;
		}
		return null;
	}
}
