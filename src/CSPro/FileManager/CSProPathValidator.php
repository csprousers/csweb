<?php

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

namespace App\CSPro\FileManager;
use Symfony\Component\Filesystem\Path;
/**
 * Description of CSProPathValidator
 *
 * @author savy
 */
class CSProPathValidator {

    /**
     * Validate path stays within root
     * @param string $path File path to validate
     * @param string $rootFolder to check against
     */
    public static function isPathSafe(string $path, string $rootFolder): bool {
        try {
             // Symfony's Path::canonicalize() resolves . and .. but not symlinks
            $canonicalRoot = Path::canonicalize($rootFolder);
            $canonicalPath = Path::canonicalize($path);

            // Check if path is within root
            return Path::isBasePath($canonicalRoot, $canonicalPath);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
    /**
     * Validate and Sanitize path to prevent path traversal security issues
     * @param string $path File path to sanitize
     * @param string $rootFolder to check against
     * return string cleanPath
     */
    public static function validateAndSanitize(string $path, string $rootFolder): string {
        // Reject null bytes immediately
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException("Path contains invalid characters (null byte).");
        }
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        if (str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Backslashes not allowed');
        }

        $segments = array_filter(
            explode('/', $path),
            fn($s) => $s !== '' && $s !== '.'
        );
        
        $cleanPath = implode('/', $segments);
        $fullPath = $rootFolder . '/' . $cleanPath;

        if (!self::isPathSafe($fullPath, $rootFolder)) {
            throw new \InvalidArgumentException('Path escapes root');
        }

        if (!self::isPathLengthSafe($fullPath)) {
            throw new \InvalidArgumentException('Path too long');
        }

        return $cleanPath;
    }
    
    /**
     * Path length validation clean_path 
     */
    public static function isPathLengthSafe(string $fullPath): bool {
    // Conservative limit (works on all platforms)
        return strlen($fullPath) <= 255;
    }

    /**
     * Validate filename - reject if unsafe, preserve if safe
     * Allows Unicode but rejects dangerous characters
     */
    public static function isFilenameSafe(string $filename): bool {
        // Must not be empty
        if (empty($filename)) {
            return false;
        }

        // Length limit
        if (mb_strlen($filename, 'UTF-8') > 255) {
            return false;
        }

        // Must not contain path separators
        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        // Must not contain path traversal
        if (str_contains($filename, '..')) {
            return false;
        }

        // Must not contain null bytes
        if (str_contains($filename, "\0")) {
            return false;
        }

        // Must not contain shell metacharacters (dangerous for any file processing)
        if (preg_match('/[;|&$`<>"\']/', $filename)) {
            return false;
        }

        // Must not start with dot (hidden files)
        if (str_starts_with($filename, '.')) {
            return false;
        }
        
        // Windows Reserved Names Check
        $reserved = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $basename = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
        if (in_array($basename, $reserved)) {
            return false;
        }

        // Must only contain safe characters: Unicode letters/numbers + safe punctuation
        // Allow: letters, numbers, spaces, dots, hyphens, underscores, parentheses, brackets
        if (!preg_match('/^[\p{L}\p{N}\s.\-_()[\]{}]+$/u', $filename)) {
            return false;
        }

        // Reject consecutive spaces
        if (preg_match('/\s{2,}/', $filename)) {
            return false;
        }

        // Reject leading/trailing spaces
        if ($filename !== trim($filename)) {
            return false;
        }
        return true;
    }
}
