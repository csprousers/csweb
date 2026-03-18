<?php

namespace App\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use App\CSPro\FileManager\CSProFileManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use App\Service\OAuthHelper;
use App\CSPro\CSProResponse;
use App\Security\FilesVoter;
use App\CSPro\FileManager\CSProPathValidator;
use App\CSPro\FileManager\FileSecurityValidator;

class FilesController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, 
                                private LoggerInterface $logger, private FileSecurityValidator $fileValidator) {

    }

    //regex search for the route does not list the fileinfo if it has content keyword at the end of the route.

    #[Route('/files/{filePath}', methods: ['GET'], requirements: ['filePath' => '.*(?<!content)$'])]
    function getFileInfo(Request $request, $filePath): CSProResponse {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_READ);

        $fileManager = new CSProFileManager($this->logger);
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $fileInfo = $fileManager->getFileInfo($filePath);
        if ($fileInfo) {
            $response = new CSProResponse(json_encode($fileInfo, JSON_THROW_ON_ERROR));
            $response->headers->set('Content-Length', strlen($response->getContent()));
        } else {
            $response = new CSProResponse();
            $response->setError(404, 'file_not_found', 'File not found');
            $response->headers->set('Content-Length', strlen($response->getContent()));
        }
        return $response;
    }

    #[Route('/files/{filePath}/content', methods: ['GET'], requirements: ['filePath' => '.+'])]
    function getFileContent(Request $request, $filePath): Response {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_READ);

        $fileManager = new CSProFileManager($this->logger);
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $ifNoneMatch = $request->headers->get('If-None-Match');

        $fileInfo = $fileManager->getFileInfo($filePath);
        if ($fileInfo) {
            if (!empty($ifNoneMatch) && ($ifNoneMatch === $fileInfo->md5)) {
                $response = new CSProResponse(json_encode(
                                ["code" => 304, "description" => "file not modified since last time downloaded according to ETag"]), CSProResponse::HTTP_NOT_MODIFIED);
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                // Use the safe name and directory from the validated FileInfo object
                $fileName = $fileManager->rootFolder . '/' . $fileInfo->directory . '/' . $fileInfo->name;
                //BinaryFileResponse sets the correct 'Content-Type' based on the filename which will ensure
                //Apache compression if it mataches the filter type in deflate_module configuration
                // Get safe content type using binary inspection
                $safeMimeType = $this->fileValidator->getSafeContentType($fileName);
                $response = new BinaryFileResponse($fileName);
                $response->headers->set('Content-Type', $safeMimeType); // Override with safe type
                $response->headers->set('X-Content-Type-Options', 'nosniff'); // Security hardening
                $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileInfo->name);
                $response->headers->set('Content-MD5', $fileInfo->md5);
                $response->headers->set('ETag', $fileInfo->md5);
            }
        } else {
            $response = new CSProResponse();
            $response->setError(404, 'file_not_found', 'File not found');
            $response->headers->set('Content-Length', strlen($response->getContent()));
        }
        return $response;
    }

    function addOrUpdateFileContent(Request $request, $filePath): CSProResponse {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);

        $fileManager = new CSProFileManager($this->logger);
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $md5Content = $request->headers->get('Content-MD5');
        $contentLengthFromHeader = $request->headers->get('Content-Length');
        $response = $content = null;
        $cleanPath = CSProPathValidator::validateAndSanitize($filePath, $fileManager->rootFolder);
        if ($request->isMethod('PUT')) {
            $content = $request->getContent();
        } else {//POST can upload the content in the body or as multi-part form data
            if (count($_FILES) === 0) {
                $content = $request->getContent();
            } else {
                //for now writing out only one file : assuming $filepath is path to upload the fileto
                $keys = array_keys($_FILES);
                if (!empty($_FILES[$keys[0]]['tmp_name'])) {
                    $content = file_get_contents($_FILES[$keys[0]]['tmp_name']);
                    $contentLengthFromHeader = $_FILES['file']['size'];
                    $cleanPath .= '/' . $_FILES[$keys[0]]['name']; //append the name to the filePath for POST $_FILES
                }
            }
        }
        $contentEncoding = $request->headers->get('Content-Encoding');
        if (strpos($contentEncoding, "deflate") !== false) {
            $content = gzuncompress($content);
            if($content === false) {
                $response = new CSProResponse();
                $response->setError(400, 'file_save_error', 'Error decompressing file.');
                return $response;
            }
        }
        $contentLength = strlen($content);
        $saveFile = (!isset($md5Content) && isset($contentLengthFromHeader)) ? $contentLength == $contentLengthFromHeader : md5($content) === $md5Content;

        if ($saveFile) {
            $targetPath = $fileManager->rootFolder . '/' . $cleanPath;

            // Extract filename from the clean path for extension checking
            $filename = basename($cleanPath);
            // Validate against API policy (Lax mode)
            // Since we have the raw content, we pass the filename and content
            $validation = $this->fileValidator->validateFile($content, $filename, false);
            if (!$validation['valid']) {
                $this->logger->warning("API Upload Blocked: " . $validation['reason'] . " for path: " . $filePath);
                $response = new CSProResponse();
                $response->setError(403, 'file_security_error', 'File rejected: ' . $validation['reason']);
                return $response;
            }
  
            if (is_dir($targetPath)) {
                $response = new CSProResponse();
                $response->setError(400, 'file_save_error', 'Error writing file. Filename is a directory');
            } else {
                $fileInfo = $fileManager->putFile($cleanPath, $content);
                if (isset($fileInfo)) {
                    $response = new CSProResponse(json_encode($fileInfo, JSON_THROW_ON_ERROR));
                } else {
                    $this->logger->error('Internal error writing file' . $filePath);
                    $response = new CSProResponse();
                    $response->setError(500, 'file_save_error', 'Error writing file');
                }
            }
        } else {
            $response = new CSProResponse();
            $response->setError(403, 'file_save_failed', 'Unable to write to filePath. Content length or md5 does not match uploaded file contents or md5.');
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/files/{filePath}/content', methods: ['PUT'], requirements: ['filePath' => '.+'])]
    function updateFileContent(Request $request, $filePath): CSProResponse {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);
        return $this->addOrUpdateFileContent($request, $filePath);
    }

    #[Route('/files/{filePath}/content', methods: ['POST'], requirements: ['filePath' => '.+'])]
    function addFileContent(Request $request, $filePath): CSProResponse {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);
        return $this->addOrUpdateFileContent($request, $filePath);
    }
}
