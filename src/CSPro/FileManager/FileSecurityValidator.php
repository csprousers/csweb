<?php

namespace App\CSPro\FileManager;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;

class FileSecurityValidator {

    private const EXECUTABLE_MIME_TYPES = [
        // Executables
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-executable',
        'application/x-dosexec',
        'application/vnd.microsoft.portable-executable',
        // Scripts
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-bash',
        'text/x-python',
        'text/x-perl',
        'application/x-perl',
        // Batch files
        'application/bat',
        'application/x-bat',
        'application/x-msdos-batch',
        // Scripts (Windows)
        'text/vbscript',
        'application/x-vbscript',
        // Java
        'application/java-archive',
        'application/x-java-archive',
        'application/java-vm',
    ];
    private const WEB_EXECUTABLE_MIME_TYPES = [
        // HTML (can contain JavaScript)
        'text/html',
        'application/xhtml+xml',
        // JavaScript
        'application/javascript',
        'application/x-javascript',
        'text/javascript',
        // SVG (can contain scripts)
        'image/svg+xml',
        // XML (can be exploited)
        'application/xml',
        'text/xml',
    ];
    private const EXECUTABLE_EXTENSIONS = [
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'csh',
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps',
        'py', 'pl', 'rb', 'vbs', 'vbe', 'jar', 'class',
        'dll', 'scr', 'msi', 'app', 'deb', 'rpm', 'dmg',
    ];
    private const WEB_EXECUTABLE_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xml',
        'js', 'mjs',
        'svg',
    ];

    public function __construct(
            private ?MimeTypes $mimeTypes = null
    ) {
        // Use default MimeTypes if not injected
        $this->mimeTypes = $mimeTypes ?? MimeTypes::getDefault();
    }

    /**
     * Main entry point.
     * @return array{valid: bool, reason: string|null}
     */
    public function validateFile(File|string|null $file, string $filename, bool $dashboardMode = false): array {
        $extension = $this->getExtension($filename);
        $mimeType = $this->resolveMimeType($file);

        // Call the policy check based on mode
        return $dashboardMode ? $this->checkDashboardPolicy($mimeType, $extension) : $this->checkApiPolicy($mimeType, $extension);
    }

    /**
     * Validates against API rules (Lax)
     * @return array{valid: bool, reason: string|null}
     */
    private function checkApiPolicy(?string $mime, string $ext): array {
        if (in_array($ext, self::EXECUTABLE_EXTENSIONS, true)) {
            return ['valid' => false, 'reason' => "Dangerous extension: .$ext"];
        }

        if ($mime && in_array($mime, self::EXECUTABLE_MIME_TYPES, true)) {
            return ['valid' => false, 'reason' => "Dangerous MIME type: $mime"];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Validates against Dashboard rules (Strict)
     * @return array{valid: bool, reason: string|null}
     */
    private function checkDashboardPolicy(?string $mime, string $ext): array {
        // First, run the base API check
        $apiResult = $this->checkApiPolicy($mime, $ext);
        if (!$apiResult['valid']) {
            return $apiResult;
        }

        // Additional UI-specific checks
        if (in_array($ext, self::WEB_EXECUTABLE_EXTENSIONS, true)) {
            return ['valid' => false, 'reason' => "Web-executable extension prohibited: .$ext"];
        }

        if ($mime && in_array($mime, self::WEB_EXECUTABLE_MIME_TYPES, true)) {
            return ['valid' => false, 'reason' => "Web-executable content prohibited: $mime"];
        }

        if ($mime && $ext && !$this->isMimeExtensionMatch($mime, $ext)) {
            return ['valid' => false, 'reason' => "MIME/Extension mismatch (Spoofing detected)"];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Internal Helper: Resolve MIME once to optimize performance.
     */
    private function resolveMimeType(File|string|null $file): ?string {
        // 1. If it is a Symfony File object (from UI Uploads)
        if ($file instanceof File) {
            return $file->getMimeType();
        }

        if (is_string($file)) {
            // 2. If it is a string representing an absolute path
            if (file_exists($file)) {
                return $this->mimeTypes->guessMimeType($file);
            }

            // 3. If it's raw binary content (from API Uploads)
            // Since Symfony doesn't have a buffer guesser in 6.4, use native PHP finfo
            if (extension_loaded('fileinfo')) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                return $finfo->buffer($file) ?: null;
            }
        }

        return null;
    }

    /**
     * Get file extension (lowercase, without dot)
     */
    private function getExtension(string $filename): string {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return strtolower($extension);
    }

    /**
     * Check if MIME type matches the file extension
     * Uses Symfony's MimeTypes to check valid combinations
     */
    private function isMimeExtensionMatch(string $mimeType, string $extension): bool {
        // Get expected extensions for this MIME type
        $expectedExtensions = $this->mimeTypes->getExtensions($mimeType);

        // If no known extensions for this MIME, allow it (unknown format)
        if (empty($expectedExtensions)) {
            return true;
        }

        // Check if the file's extension is in the list of valid extensions
        return in_array($extension, $expectedExtensions, true);
    }

    /**
     * Resolves a safe Content-Type header for serving files.
     * Forces 'application/octet-stream' for any risky types to prevent 
     * unintended execution or XSS in the browser.
      va */
    public function getSafeContentType(File|string $file): string {
        // Reuse our optimized resolution logic
        $mimeType = $this->resolveMimeType($file);

        if (!$mimeType) {
            return 'application/octet-stream';
        }

        // 1. Block System Executables
        if (in_array($mimeType, self::EXECUTABLE_MIME_TYPES, true)) {
            return 'application/octet-stream';
        }

        // 2. Block Web-Executable types (HTML, JS, SVG) to prevent XSS
        if (in_array($mimeType, self::WEB_EXECUTABLE_MIME_TYPES, true)) {
            return 'application/octet-stream';
        }

        // 3. For all other types, return the detected MIME
        return $mimeType;
    }
}
