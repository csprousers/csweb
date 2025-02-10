<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\CSProJsonValidator;
use AppBundle\Security\AppsVoter;

class AppsController extends AbstractController implements ApiTokenAuthenticatedController {

    private $appsDir;

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    #[Route('/apps', methods: ['GET'])]
            function getApps(Request $request): CSProResponse {
        // Download list of apps in JSON from cspro_apps table
        $stm = 'SELECT name, description, build_time as buildTime, version 
				FROM cspro_apps';
        $result = $this->pdo->fetchAll($stm);

        // Generate app spec json from query result
        foreach ($result as &$row) {

            // Same in every file
            $row['FileType'] = 'Application Deployment Specification';

            // Convert build time to RFC3339
            $buildTimeUtc = \DateTime::createFromFormat('Y-m-d H:i:s', $row['buildTime'], new \DateTimeZone("UTC"));
            $row ['buildTime'] = $buildTimeUtc->format(\DateTime::RFC3339);
        }
        unset($row);
        $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }

    #[Route('/apps/{appName}', methods: ['PUT'], requirements: ['appName' => '.+'])]
            function updateApp(Request $request, $appName): CSProResponse {
        // Upload application package
        // Package is a CSPro application bundle which
        // is just a zip archive. This file is copied to the apps
        // directory. The zip archive contains a deployment
        // spec file named package.json (or .csds for legacy apps).
        // Extract this file, read the parameters from it and add
        // them to cspro_apps table in database.

        $this->denyAccessUnlessGranted(AppsVoter::APPS_ALL);
        $contentLength = $request->headers->get('Content-Length');
        $content = $request->getContent();
        if (isset($contentLength) && $contentLength != strlen($content)) {
            $this->logger->error('Invalid content length on app package' . $appName);
            $response = new CSProResponse();
            $response->setError(403, 'app_upload_failed', 'Unable to write app package. Content length header does not match uploaded file contents.');
            return $response;
        }

        // Make sure apps directory exists.
        $appsDir = $this->getParameter('csweb_api_files_folder') . DIRECTORY_SEPARATOR . 'apps';
        if (!is_dir($appsDir)) {

            $this->logger->info('Creating apps directory' . $appsDir);
            if (!@mkdir($appsDir)) {
                $this->logger->error('Unable to create apps directory' . $appsDir);
                $response = new CSProResponse();
                $response->setError(403, 'app_upload_failed', 'Unable to create apps directory ' . $appsDir);
                return $response;
            }
        }

        // Save the package zip file to a temp file in apps directory
        // Later we will rename to give it name of app but need to make
        // sure it is valid first
        $filePath = @tempnam($appsDir, 'tmpApp');
        if ($filePath === FALSE) {
            $this->logger->error('Internal error creating temp file for unzip' . $filePath);
            $response = new CSProResponse();
            $response->setError(500, 'app_upload_failed', 'Error creating temp file');
            return $response;
        }

        if (@file_put_contents($filePath, $content) === FALSE) {
            @unlink($filePath);
            $this->logger->error('Internal error writing app package zip file' . $filePath);
            $response = new CSProResponse();
            $response->setError(500, 'app_upload_failed', 'Error writing file');
            return $response;
        }

        // Extract the spec file from zip archive
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive;
        } else {
            @unlink($filePath);
            $this->logger->error('Failed to load class ZipArchive. Ensure that zip support in PHP is enabled');
            $response = new CSProResponse();
            $response->setError(500, 'app_upload_failed', 'Failed to load class ZipArchive. Ensure that zip support in PHP is enabled');
            return $response;
        }

        if ($zip->open($filePath) !== TRUE) {
            @unlink($filePath);
            $this->logger->error('Failed to open app package zip file ' . $filePath);
            $response = new CSProResponse();
            $response->setError(500, 'app_upload_failed', 'Failed to open app package zip file ' . $appName);
            return $response;
        }

        $appSpec = $zip->getFromName('package.json');
        if (!$appSpec)
            $appSpec = $zip->getFromName('package.csds');
        $this->logger->debug('Found spec ' . $appSpec);

        $zip->close();

        // Validate JSON spec file
        $uri = '#/definitions/ApplicationDeploymentSpecification';
        $csproJsonValidator = new CSProJsonValidator($this->logger);
        $csproJsonValidator->validateEncodedJSON($appSpec, $uri);

        $params = json_decode($appSpec, true, 512, JSON_THROW_ON_ERROR); // 2nd param to get as array
        if (empty($params['name']) || empty($params['buildTime']) || empty($params['version'])) {
            @unlink($filePath);
            $this->logger->error('Invalid app spec - missing required fields ' . $filePath);
            $response = new CSProResponse();
            $response->setError(400, 'invalid_app_specification', 'Invalid application specification supplied. Name, Version and BuildTime are required.');
            return $response;
        }

        if ($params['name'] != $appName) {
            @unlink($filePath);
            $this->logger->error('Invalid app spec - Name does not match name in url');
            $response = new CSProResponse();
            $response->setError(400, 'invalid_app_specification', 'Name does not match name in url.');
            return $response;
        }

        $invalidFileChars = ['\\', '/', ':', '*', '?', '"', '<', '>', '|'];
        $params['path'] = 'apps' . DIRECTORY_SEPARATOR . str_replace($invalidFileChars, '_', $appName) . '.zip';

        try {
            // Check for old version of the app and get signature so we can clear update cache for old version
            $oldSignature = $this->pdo->fetchValue('SELECT signature FROM cspro_apps WHERE name = :appName;',
                    ['appName' => ['appName' => $appName]]);

            // Add to/update cspro_apps table in database

            $buildTime = date_create($params['buildTime'])->format('Y-m-d H:i:s');
            $signature = md5($appSpec);
            $filesJson = json_encode($params['files'], JSON_THROW_ON_ERROR);

            $stmt = $this->pdo->prepare('INSERT INTO cspro_apps (name, description, build_time, path, files, signature, version)
				VALUES (:name, :description, :build_time, :path, :files, :signature, :version)
				ON DUPLICATE KEY UPDATE name=:name, description=:description, build_time=:build_time, path=:path, files=:files, signature=:signature, version=:version');
            $stmt->bindParam(':name', $params ['name']);
            $stmt->bindParam(':description', $params ['description']);
            $stmt->bindParam(':build_time', $buildTime);
            $stmt->bindParam(':path', $params['path']);
            $stmt->bindParam(':files', $filesJson);
            $stmt->bindParam(':signature', $signature);
            $stmt->bindParam(':version', $params ['version']);
            $stmt->execute();

            // Rename zip file
            if (@rename($filePath, $this->getParameter('csweb_api_files_folder') . DIRECTORY_SEPARATOR . $params['path']) === FALSE) {
                @unlink($filePath);
                $this->logger->error('Failed to rename app package zip file ' . $filePath);
                $response = new CSProResponse();
                $response->setError(500, 'app_upload_failed', 'Failed to rename app package zip file ' . $appName);
                return $response;
            }

            if ($oldSignature)
                $this->ClearUpdateCache($oldSignature);

            $response = new CSProResponse(json_encode(["code" => 200, "description" => 'The application ' . $appName . ' was successfully updated.'], JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            @unlink($filePath);
            $this->logger->error('Failed updating app: ' . $appName, ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError(500, 'app_update_failed', 'Failed to update application in database.');
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/apps/{appName}', methods: ['GET'], requirements: ['appName' => '.+'])]
            function getApp(Request $request, $appName): Response {

        $this->logger->debug("Downloading app: $appName");

        $stm = 'SELECT id, build_time, path, files, signature FROM cspro_apps WHERE name = :appName;';
        $bind = ['appName' => ['appName' => $appName]];

        $appInfo = $this->pdo->fetchOne($stm, $bind);
        if ($appInfo == false) {
            $response = new CSProResponse();
            $response->setError(404, 'app_not_found', 'Application ' . $appName . ' not found');
            $this->logger->error('Failed downloading app: ' . $appName . '. Not found in database.');
            return $response;
        }

        $appPath = $appInfo['path'];
        $appPath = $this->getParameter('csweb_api_files_folder') . DIRECTORY_SEPARATOR . $appPath;
        if (!file_exists($appPath)) {
            $response = new CSProResponse();
            $response->setError(404, 'app_not_found', 'Application ' . $appName . ' not found');
            $this->logger->error('Failed downloading app: ' . $appName . '. File ' . $appPath . ' not found.');
            return $response;
        }

        // Check if build time is newer
        $clientBuildTimeHeader = $request->headers->get('x-csw-package-build-time');
        if ($clientBuildTimeHeader) {
            $clientBuildTime = date_create($clientBuildTimeHeader)->format('Y-m-d H:i:s');
            if ($clientBuildTime == false) {
                $this->logger->error("Missing or invalid package build time $clientBuildTimeHeader");
                $response = new CSProResponse();
                $response->setError(400, 'invalid_header', 'Missing or invalid package build time. Not a valid RFC3339 formatted date time.');
                return $response;
            }
            $serverBuildTime = date_create($appInfo['build_time'])->format('Y-m-d H:i:s');
            $this->logger->debug("Client build time $clientBuildTime server build time $serverBuildTime");
            if ($clientBuildTime >= $serverBuildTime) {
                $this->logger->debug('Server build is same or newer');
                $response = new CSProResponse();
                $response->setNotModified();
                return $response;
            }
        }

        // Check signature
        $clientSignature = $request->headers->get('If-None-Match');
        $serverSignature = $appInfo['signature'];
        if ($clientSignature && $serverSignature) {
            $this->logger->debug("Client package $clientSignature server package $serverSignature");
            if ($clientSignature == $serverSignature) {
                $this->logger->debug('Server signature matches');
                $response = new CSProResponse();
                $response->setNotModified();
                return $response;
            }

            // Check for cached update from client version to server version
            $cachedAppPath = $this->CachedUpdatePath($serverSignature) . DIRECTORY_SEPARATOR . $clientSignature . '.zip';

            $this->logger->debug("Checking for cached package $cachedAppPath");
            if (file_exists($cachedAppPath)) {
                $this->logger->debug("Returning cached package");
                $response = new BinaryFileResponse($cachedAppPath);
            } else {
                // No cached package, create one
                @mkdir(dirname($cachedAppPath), 0777, true);

                $clientFilesHeader = $request->headers->get('x-csw-package-files');
                if ($clientFilesHeader) {

                    $clientFilesJson = @gzuncompress(@base64_decode($clientFilesHeader));
                    if (!$clientFilesJson) {
                        $this->logger->error("Error decompressing x-csw-package-files header $clientFilesHeader");
                        $response = new CSProResponse();
                        $response->setError(400, 'invalid_header', 'Error decompressing x-csw-package-files');
                        return $response;
                    }

                    $this->logger->debug("Client files JSON: $clientFilesJson");

                    $csproJsonValidator = new CSProJsonValidator($this->logger);
                    $csproJsonValidator->validateEncodedJSON($clientFilesJson, '#/definitions/ApplicationDeploymentFiles');
                    $clientFiles = json_decode($clientFilesJson, true, 512, JSON_THROW_ON_ERROR);

                    $this->logger->debug("Create new cached package");
                    if (!copy($appPath, $cachedAppPath)) {
                        $this->logger->error("Failed to copy package $appPath ==> $cachedAppPath");
                        $response = new CSProResponse();
                        $response->setError(500, 'app_download_failed', 'Failed to copy application package');
                        return $response;
                    }

                    $zip = new \ZipArchive;
                    if ($zip->open($cachedAppPath) !== TRUE) {
                        @unlink($cachedAppPath);
                        $this->logger->error('Failed to open app package zip file ' . $cachedAppPath);
                        $response = new CSProResponse();
                        $response->setError(500, 'app_download_failed', 'Failed to open app package zip file ' . $cachedAppPath);
                        return $response;
                    }

                    $serverFiles = json_decode($appInfo['files'], true, 512, JSON_THROW_ON_ERROR);

                    $filesToExclude = array_filter($serverFiles,
                            function ($sf) use ($clientFiles) {
                                $match = current(array_filter($clientFiles, fn($cf) => $cf['path'] == $sf['path']));
                                if (!$match) {
                                    return false; // File not yet on client so it should be sent
                                }
                                if (isset($sf['onlyOnFirstInstall']) && $sf['onlyOnFirstInstall'])
                                    return true; // Only on first install should not be sent
                                return isset($sf['signature']) && isset($match['signature']) && $match['signature'] == $sf['signature'];
                            });
                    $this->logger->debug('Client files ' . json_encode($clientFiles, JSON_THROW_ON_ERROR));
                    $this->logger->debug('Server files ' . json_encode($serverFiles, JSON_THROW_ON_ERROR));
                    $this->logger->debug('Exclude files ' . json_encode($filesToExclude, JSON_THROW_ON_ERROR));

                    foreach ($filesToExclude as $fileToExclude) {

                        // Zip files should use / but the ones generated by old versions of .NET
                        // use \\ so handle both
                        $pathInZip = str_replace('\\', '/', $fileToExclude['path']);
                        if (substr($pathInZip, 0, 2) == "./")
                            $pathInZip = substr($pathInZip, 2);
                        $this->logger->debug("Remove file $pathInZip");
                        if (!($zip->deleteName($pathInZip) == TRUE ||
                                $zip->deleteName(str_replace('/', '\\', $pathInZip)) == TRUE)) {
                            $zip->close();
                            @unlink($cachedAppPath);
                            $this->logger->error("Failed to delete file $pathInZip from package zip file");
                            $response = new CSProResponse();
                            $response->setError(500, 'app_download_failed', "Failed to delete file $pathInZip from package zip file ");
                            return $response;
                        }
                    }
                    $zip->close();
                    $response = new BinaryFileResponse($cachedAppPath);
                } else {
                    // Missing files header, send the whole package
                    $response = new BinaryFileResponse($appPath);
                }
            }
        } else {
            // First time install, send the whole package
            $response = new BinaryFileResponse($appPath);
        }

        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($appPath));

        return $response;
    }

    #[Route('/apps/{appName}', methods: ['DELETE'], requirements: ['appName' => '.+'])]
    function deleteApp(Request $request, $appName): CSProResponse {
        $this->denyAccessUnlessGranted(AppsVoter::APPS_ALL);
        try {
            $stm = 'SELECT path, signature FROM cspro_apps WHERE name = :appName;';
            $bind = ['appName' => ['appName' => $appName]];

            $appInfo = $this->pdo->fetchOne($stm, $bind);
            $appPath = $appInfo['path'];
            $signature = $appInfo['signature'];

            if ($appPath == false) {
                $response = new CSProResponse();
                $response->setError(404, 'app_not_found', 'Application ' . $appName . ' not found');
                $this->logger->error('Failed deleting app: ' . $appName . '. Not found in database.');
                return $response;
            }

            $appPath = $this->getParameter('csweb_api_files_folder') . DIRECTORY_SEPARATOR . $appPath;
            @unlink($appPath);

            $stm = 'DELETE FROM cspro_apps WHERE name = :appName';
            $bind = ['appName' => ['appName' => $appName]];
            $row_count = $this->pdo->fetchAffected($stm, $bind);

            if ($row_count == 1) {
                $response = new CSProResponse(json_encode(["code" => 200, "description" => 'The application ' . $appName . ' was successfully deleted.'], JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
            } else {
                $response = new CSProResponse ();
                $response->setError(404, 'app_delete_error', 'Application ' . $appName . ' not found ');
                $this->logger->error('Failed deleting app: ' . $appName);
            }

            if ($signature)
                $this->ClearUpdateCache($signature);
        } catch (\Exception $e) {
            $this->logger->error('Failed deleting app: ' . $appName, ["context" => (string) $e]);
            $response = new CSProResponse ();
            $response->setError(500, 'app_delete_error', 'Failed deleting dictionary');
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    function CachedUpdatePath($signature) {
        return implode(DIRECTORY_SEPARATOR,
                [$this->getParameter('csweb_api_files_folder'), 'apps', 'updates', $signature]);
    }

    function ClearUpdateCache($signature) {
        $cacheDir = $this->CachedUpdatePath($signature);
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.zip') as $filename) {
                @unlink($filename);
            }
            @rmdir($cacheDir);
        }
    }

}
