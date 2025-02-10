<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use AppBundle\CSPro\FileManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;

class FilesController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    //regex search for the route does not list the fileinfo if it has content keyword at the end of the route.

    #[Route('/files/{filePath}', methods: ['GET'], requirements: ['filePath' => '.*(?<!content)$'])]
    function getFileInfo(Request $request, $filePath): CSProResponse {
        $fileManager = new FileManager();
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
        $fileManager = new FileManager();
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $ifNoneMatch = $request->headers->get('If-None-Match');

        $fileInfo = $fileManager->getFileInfo($filePath);
        if ($fileInfo) {
            if (!empty($ifNoneMatch) && ($ifNoneMatch === $fileInfo->md5)) {
                $response = new CSProResponse(json_encode(
                                ["code" => 304, "description" => "file not modified since last time downloaded according to ETag"]), CSProResponse::HTTP_NOT_MODIFIED);
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                $fileName = $this->getParameter('csweb_api_files_folder') . DIRECTORY_SEPARATOR . $filePath;
                $response = new BinaryFileResponse($fileName);
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

    #[Route('/files/{filePath}/content', methods: ['PUT'], requirements: ['filePath' => '.+'])]
    function updateFileContent(Request $request, $filePath): CSProResponse {
        $fileManager = new FileManager();
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $md5Content = $request->headers->get('Content-MD5');
        $contentLength = $request->headers->get('Content-Length');
        $content = $request->getContent();

        $response = null;
        if (!isset($md5Content) && isset($contentLength)) {
            $saveFile = $contentLength == strlen($content);
        } else {
            //echo 'generated md5 :' . md5($content);
            //echo '$md5Content :' .$md5Content;
            $saveFile = md5($content) === $md5Content;
        }

        if ($saveFile) {
            $invalidFileName = is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $filePath);
            if ($invalidFileName == true) {
                $response = new CSProResponse();
                $response->setError(400, 'file_save_error', 'Error writing file. Filename is a directory');
            } else {
                $fileInfo = $fileManager->putFile($filePath, $content);
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

    #[Route('/files/{filePath}/content', methods: ['POST'], requirements: ['filePath' => '.+'])]
    function addFileContent(Request $request, $filePath): CSProResponse {
        $fileManager = new FileManager();
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $md5Content = $request->headers->get('Content-MD5');
        $response = null;
        $saveFile = false;
        if (count($_FILES) === 0) {
            $content = $request->getContent();
            //echo 'Content is: ' . $content;
            //echo 'generated md5 :' . md5($content);
            $saveFile = md5($content) === $md5Content;
            $contentLength = $request->headers->get('Content-Length');
            if (!isset($md5Content) && isset($contentLength)) {
                $saveFile = $contentLength == strlen($content);
            }
        } else { //for now writing out only one file : assuming $filepath is path to upload the fileto
            $keys = array_keys($_FILES);
            if (!empty($_FILES[$keys[0]]['tmp_name'])) {
                $content = file_get_contents($_FILES[$keys[0]]['tmp_name']);
            }
            $validPath = is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $filePath);
            if (isset($content) && $validPath === true && strlen($content) === $_FILES['file']['size']) {
                //	echo 'Content is: ' . $content;
                $filePath .= '/' . $_FILES[$keys[0]]['name']; //append the name to the filePath for POST $_FILES
                $saveFile = true;
            }
        }
        if ($saveFile) {
            $fileInfo = $fileManager->putFile($filePath, $content);
            if (isset($fileInfo)) {
                $response = new CSProResponse(json_encode($fileInfo, JSON_THROW_ON_ERROR));
            } else {
                $response = new CSProResponse();
                $response->setError(500, 'file_save_error', 'Error writing file');
            }
        } else {
            $response = new CSProResponse();
            $response->setError(403, 'file_save_failed', 'Unable to write to filePath. Content length or md5 does not match uploaded file contents or md5.');
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
