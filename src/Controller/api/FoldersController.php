<?php


namespace App\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\CSPro\FileManager\CSProFileManager;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use App\Service\OAuthHelper;
use App\CSPro\CSProResponse;
use App\CSPro\FileManager\CSProPathValidator;

class FoldersController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger)
    {
    }

    #[Route('/folders/{folderPath}', methods: ['GET'], requirements: ['folderPath' => '.*'])]
    function getDirectoryListing(Request $request, $folderPath): CSProResponse {
        $getFileMd5 = $request->headers->get('x-csw-get-file-md5');
        $getFileMd5 = isset($getFileMd5) ? filter_var($getFileMd5, FILTER_VALIDATE_BOOLEAN) : true;

        $fileManager = new CSProFileManager($this->logger);
        $fileManager->rootFolder = $this->getParameter('csweb_api_files_folder');
        $dirList = $fileManager->getDirectoryListing($folderPath, $getFileMd5);
        $response = null;
        $cleanPath = CSProPathValidator::validateAndSanitize($folderPath, $fileManager->rootFolder);
        if (is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $cleanPath)) {
            $response = new CSProResponse(json_encode($dirList, JSON_THROW_ON_ERROR));
        } else {
            $response = new CSProResponse();
            $response->setError(404, 'directory_not_found', 'Directory not found');
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }
}
