<?php

namespace App\CSPro\FileManager;

use App\CSPro\FileManager\FileInfo;
use App\CSPro\FileManager\CSProPathValidator;
use Psr\Log\LoggerInterface;
use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;

/* use Aws\S3\S3Client;
  use League\Flysystem\AwsS3v3\AwsS3Adapter;
  use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
  use MicrosoftAzure\Storage\Blob\BlobRestProxy;
  use League\Flysystem\Adapter\Ftp as FtpAdapter;
  use Google\Cloud\Storage\StorageClient;
  use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
  use OpenCloud\OpenStack;
  use OpenCloud\Rackspace;
  use League\Flysystem\Rackspace\RackspaceAdapter;
  use League\Flysystem\Sftp\SftpAdapter; */

class FileManagerFlysystem {

    public $rootFolder = null;
    public $adapter = 'local';
    private array $default_config = [
        'adapter' => 'local',
    ];
    private $filesystem = null;
    private $config = null;

    public function __construct($config = [], private LoggerInterface $logger) {
        // should this be a singleton?
        $this->config = array_merge($this->default_config, $config);
        $this->rootFolder = $this->config['rootFolder']; // array_get($this->config, 'rootFolder');
        if ($this->rootFolder)
            $this->filesystem = new LeagueFilesystem($this->getAdapter($this->config['adapter']));
    }

    private function getAdapter($adapter_slug = null) {
        $adapter_key = $adapter_slug ?? $this->config['adapter'];
        if ($adapter_key == 'local')
            return new LocalAdapter($this->rootFolder);
        /* else if($adapter_key == 's3') return new AwsS3Adapter($this->rootFolder);
          else if($adapter_key == 'azure') return new AzureBlobStorageAdapter($this->rootFolder);
          else if($adapter_key == 'rackspace') return new RackspaceAdapter($this->rootFolder);
          else if($adapter_key == 'google') return new GoogleStorageAdapter($this->rootFolder);
          else if($adapter_key == 'sftp') return new SftpAdapter($this->rootFolder);
          else if($adapter_key == 'ftp') return new FtpAdapter($this->rootFolder); */
        else
            return new LocalAdapter($this->rootFolder);
    }

    public function getFilesystem() {
        if (!$this->filesystem)
            $this->filesystem = new LeagueFilesystem($this->getAdapter($this->config['adapter']));
        return $this->filesystem;
    }

    private function returnFileInfo($file_object) {
        $filesystem = $this->getFilesystem();
        $fileInfo = new FileInfo();
        foreach ($file_object as $k => $v)
            $fileInfo->{$k} = $v;

        if ($fileInfo->type == 'dir')
            $fileInfo->type = 'directory';
        $fileInfo->name = basename($fileInfo->path);
        $fileInfo->directory = empty($fileInfo->dirname) ? '/' : $fileInfo->dirname;
        if ($fileInfo->type == 'file') {
            $fileInfo->md5 = @md5($filesystem->read($fileInfo->path));
        } else if ($fileInfo->type == 'directory') {
            unset($fileInfo->md5);
            unset($fileInfo->size);
        }
        return $fileInfo;
    }

    public function getDirectoryListing($folderPath = '/') {
        try {
            // Secure the path before passing to Flysystem
            $cleanPath = CSProPathValidator::validateAndSanitize($folderPath, $this->rootFolder);
            $filesystem = $this->getFilesystem();
            $file_listing = $filesystem->listContents($cleanPath);
            return collect($file_listing)->map(fn($v) => $this->returnFileInfo($v))->toArray();
        } catch (\Exception $e) {
            $this->logger->error("Invalid path in getDirectoryListing: {$folderPath} - {$e->getMessage()}");
            return null;
        }
    }

    public function putFile($filePath, $content) {
        try {
            $cleanPath = CSProPathValidator::validateAndSanitize($filePath, $this->rootFolder);
            // Check filename specifically for put operations
            if (!CSProPathValidator::isFilenameSafe(basename($cleanPath))) {
                return null;
            }
            $filesystem = $this->getFilesystem();
            $filesystem->write($cleanPath, $content);
            return $this->getFileInfo($cleanPath);
        } catch (\Exception $e) {
            $this->logger->error("Invalid path in putFile: {$filePath} - {$e->getMessage()}");
            return null;
        }
        return null;
    }

    public function getFileInfo($filePath) {
        if (!isset($this->rootFolder))
            return null;

        try {
            $cleanFilePath = CSProPathValidator::validateAndSanitize($filePath, $this->rootFolder);
            $filesystem = $this->getFilesystem();

            // 2. USE FILESYSTEM ABSTRACTION
            // If you keep is_file($file), it will fail on S3/Cloud storage.
            if (!$filesystem->has($cleanFilePath)) {
                return null;
            }

            $fileInfo = new FileInfo();
            $fileInfo->name = basename($cleanFilePath);
            $fileInfo->directory = dirname($cleanFilePath) === '.' ? '/' : dirname($cleanFilePath);

            // Check if it's a directory or file using Flysystem metadata
            // This replaces the manual is_dir/is_file logic
            $metadata = $filesystem->listContents($fileInfo->directory)
                    ->filter(fn($attributes) => $attributes->path() === $cleanFilePath)
                    ->first();

            if ($metadata && $metadata->isDir()) {
                $fileInfo->type = 'directory';
                unset($fileInfo->md5, $fileInfo->size, $fileInfo->lastModified);
            } else {
                $fileInfo->type = 'file';
                $fileInfo->md5 = @md5($filesystem->read($cleanFilePath));
                $fileInfo->size = $filesystem->fileSize($cleanFilePath);
                $fileInfo->lastModified = @date(\DateTime::RFC3339, $filesystem->lastModified($cleanFilePath));
            }

            return $fileInfo;
        } catch (\Exception $e) {
            $this->logger->error("Invalid path in getFileInfo: {$filePath} - {$e->getMessage()}");
            return null;
        }
    }
}
