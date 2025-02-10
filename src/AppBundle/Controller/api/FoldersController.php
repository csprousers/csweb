<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\CSPro\FileManager;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;

class FoldersController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger)
    {
    }

    #[Route('/folders/{folderPath}', methods: ['GET'], requirements: ['folderPath' => '.*'])]
    function getDirectoryListing(Request $request, $folderPath): CSProResponse {
        $fileManager = new FileManager();
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $dirList = $fileManager->getDirectoryListing($folderPath);
        $response = null;
        if (is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $folderPath)) {
            $response = new CSProResponse(json_encode($dirList, JSON_THROW_ON_ERROR));
        } else {
            $response = new CSProResponse();
            $response->setError(404, 'directory_not_found', 'Directory not found');
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
