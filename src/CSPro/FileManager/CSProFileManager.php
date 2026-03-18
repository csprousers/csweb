<?php

namespace App\CSPro\FileManager;

use App\CSPro\FileManager\CSProPathValidator;
use App\CSPro\FileManager\FileInfo;

use Psr\Log\LoggerInterface;

class CSProFileManager {

    public $rootFolder = null;
    function __construct(private LoggerInterface $logger) {
	}
    /**
     * Get directory listing with security validation
     * 
     * @param string $folderPath Folder path relative to root
     * @param bool $getFileMd5 Whether to calculate MD5 checksums
     * @return array|null Array of FileInfo objects or null on error
     */
    public function getDirectoryListing($folderPath, $getFileMd5 = true) {
        if (!isset($this->rootFolder))
            return null;

        // Validate and sanitize the folder path
        try {
            $cleanFolderPath = CSProPathValidator::validateAndSanitize($folderPath, $this->rootFolder);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error("Invalid path in getDirectoryListing: {$folderPath} - {$e->getMessage()}");
            return null;
        }

        $dirList = [];

        $absfolderPath = $this->rootFolder . '/' . $cleanFolderPath;
        if (!@is_dir($absfolderPath)) {
            return null;
        }
        // Double-check path safety after resolution
        if (!CSProPathValidator::isPathSafe($absfolderPath, $this->rootFolder)) {
            $this->logger->error("Path safety check failed for: {$absfolderPath}");
            return null;
        }
       
        $dirContents = array_diff(@scandir($absfolderPath), ['..', '.']);
        $n = 0;
        foreach ($dirContents as $file) {
             // Skip hidden files and files with unsafe names
            if (str_starts_with($file, '.')) {
                continue;
            }
            $fileInfo = new FileInfo();
            $fileInfo->name = $file;
            $fileInfo->directory = "/" . $cleanFolderPath;
            if (substr($fileInfo->directory, -1) != '/') {
                $fileInfo->directory .= '/';
            }
            $fullFilePath = $absfolderPath . '/' . $file;
            // Additional safety check: verify each file path is safe
            if (!CSProPathValidator::isPathSafe($fullFilePath, $this->rootFolder)) {
                $this->logger->error("Skipping unsafe file path: {$fullFilePath}");
                continue;
            }
            if (@is_dir($fullFilePath)) {
                $fileInfo->type = 'directory';
                unset($fileInfo->md5);
                unset($fileInfo->size);
                unset($fileInfo->lastModified);
            } else {
                $fileInfo->type = 'file';
                if ($getFileMd5) {
                    $fileInfo->md5 = @md5_file($fullFilePath);
                } else {
                    unset($fileInfo->md5);
                }
                $fileInfo->size = @filesize($fullFilePath);
                $fileInfo->lastModified = @date(\DateTime::RFC3339, @filemtime($fullFilePath));
            }
            $dirList[$n] = $fileInfo;
            $n++;
        }
        return $dirList;
    }
    
    /**
     * Put file with security validation
     * 
     * @param string $filePath File path relative to root
     * @param mixed $content File content
     * @return FileInfo|null FileInfo object or null on error
     */
    public function putFile($filePath, $content) {
        $folderPath = "";
        if (!isset($this->rootFolder) || empty($filePath))
            return null;
        // Validate and sanitize the file path
        try {
            $cleanFilePath = CSProPathValidator::validateAndSanitize($filePath, $this->rootFolder);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error("Invalid path in putFile: {$filePath} - {$e->getMessage()}");
            return null;
        }
        $absfolderPath = $this->rootFolder;
        $pos = strrpos($cleanFilePath, '/');
        if ($pos === false) {
            $fileName = $cleanFilePath;
        } else {
            $folderPath = substr($cleanFilePath, 0, $pos);
            $fileName = substr($cleanFilePath, $pos + 1);
            $absfolderPath = $this->rootFolder . '/' . $folderPath;
        }
         // Validate filename
        if (!CSProPathValidator::isFilenameSafe($fileName)) {
            $this->logger->error("Unsafe filename rejected: {$fileName}");
            return null;
        }
        
        // Create directory if it doesn't exist
        if (!is_dir($absfolderPath)) {
            $bRet = @mkdir($absfolderPath, 0777, true);
            if (!$bRet) {
                return null;
            }
        }
        // Write the contents back to the file
        $file = $absfolderPath . '/' . $fileName;
        // Final path safety check
        if (!CSProPathValidator::isPathSafe($file, $this->rootFolder)) {
            $this->logger->error("Path safety check failed for file: {$file}");
            return null;
        }
        
        // Check path length
        if (!CSProPathValidator::isPathLengthSafe($file)) {
            $this->logger->error("Path too long: {$file}");
            return null;
        }
        
        if (!(@file_put_contents($file, $content) === FALSE)) {
            $fileInfo = new FileInfo();
            $fileInfo->type = 'file';
            $fileInfo->name = $fileName;
            $fileInfo->md5 = @md5_file($file);
            $fileInfo->size = @filesize($file);
            $fileInfo->directory = $folderPath;
            $fileInfo->lastModified = @date(\DateTime::RFC3339, @filemtime($file));
            return $fileInfo;
        }
        return null;
    }
    /**
     * Get file info with security validation
     * 
     * @param string $filePath File path relative to root
     * @return FileInfo|null FileInfo object or null on error
     */
    public function getFileInfo($filePath) {
        $folderPath = "";
        if (!isset($this->rootFolder))
            return null;
        
        // Validate and sanitize the file path
        try {
            $cleanFilePath =  CSProPathValidator::validateAndSanitize($filePath, $this->rootFolder);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error("Invalid path in getFileInfo: {$filePath} - {$e->getMessage()}");
            return null;
        }

        $absfolderPath = $this->rootFolder;
        $pos = strrpos($cleanFilePath, '/');
        if ($pos === FALSE) {
            $fileName = $cleanFilePath;
        } else {
            $folderPath = substr($cleanFilePath, 0, $pos);
            $fileName = substr($cleanFilePath, $pos + 1);
            $absfolderPath = $this->rootFolder . '/' . $folderPath;
        }
        $file = $absfolderPath . '/' . $fileName;
        // Path safety check
        if (!CSProPathValidator::isPathSafe($file, $this->rootFolder)) {
            $this->logger->error("Path safety check failed: {$file}");
            return null;
        }
        if (@is_file($file) === TRUE) {
            $fileInfo = new FileInfo();
            $fileInfo->type = 'file';
            $fileInfo->name = $fileName;
            $fileInfo->md5 = @md5_file($file);
            $fileInfo->size = @filesize($file);
            $fileInfo->directory = $folderPath;
            $fileInfo->lastModified = @date(\DateTime::RFC3339, @filemtime($file));
            return $fileInfo;
        } else if (is_dir($file)) {
            $fileInfo = new FileInfo();
            $fileInfo->type = 'directory';
            $fileInfo->name = $fileName;
            $fileInfo->directory = $folderPath;
            unset($fileInfo->md5);
            unset($fileInfo->size);
            unset($fileInfo->lastModified);
            return $fileInfo;
        }
        return null;
    }
}
