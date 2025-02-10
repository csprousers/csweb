<?php

namespace AppBundle\Controller\ui;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\DataSettings;
use AppBundle\CSPro\CSProResponse;
use GuzzleHttp\Client;
use AppBundle\CSPro\FileManager;
use Symfony\Component\HttpKernel\KernelInterface;

require_once __DIR__ . '/../../../../maps/server.php';

/**
 * Description of DataSettingsController
 *
 * @author savy
 */
class DataSettingsController extends AbstractController implements TokenAuthenticatedController {

    private $dataSettings;

    public function __construct(private HttpHelper $client, private PdoHelper $pdo, KernelInterface $kernel, private LoggerInterface $logger) {
        $this->kernel = $kernel;
    }

//override the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->dataSettings = new DataSettings($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    #[Route('/dataSettings', name: 'dataSettings', methods: ['GET'])]
    public function viewDataSettingsAction(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');
        // Set the oauth token
        $dataSettings = $this->dataSettings->getDataSettings();
        $this->logger->debug('data settings ' . print_r($dataSettings, true));
        return $this->render('dataSettings.twig', ['dataSettings' => $dataSettings]);
    }

    #[Route('/getSettings', name: 'getSettings', methods: ['GET'])]
    public function getDataSettings(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');
//get data settings
        $dataSettings = $this->dataSettings->getDataSettings();
        $this->logger->debug('data settings ' . print_r($dataSettings, true));
        return $this->render('dataSettings.twig', ['dataSettings' => $dataSettings]);
    }

    #[Route('/addSetting', name: 'addSetting', methods: ['POST'])]
    public function addDataSetting(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');

        $result = [];
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $label = $dataSetting['label'];
        $this->updateMetaDataInfo($dataSetting);
        try {
            $isValidMapURL = $this->checkMapURLConnection($dataSetting);
            $isAddded = $this->dataSettings->addDataSetting($dataSetting);

            if ($isAddded === true && $isValidMapURL === true) {
                //try connectint to the server 
                $result['description'] = "Added configuration for $label";
                $result['code'] = 200;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK);
            } else {
                $result['description'] = "Failed to add  configuration for $label";
                $result['code'] = 500;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            $pattern = "/(?<=SQLSTATE\[HY\d{3}\]\s\[\d{4}\]).*/";
            $match = preg_match($pattern, $errMsg, $matchStr);
            if ($match) {
                $errMsg = $matchStr[0];
            }
            $result['description'] = "Failed to add  configuration for $label. $errMsg";
            $result['code'] = 500;
            $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->logger->error("Failed adding configuration", ["context" => (string) $e]);
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/updateSetting', name: 'updateSetting', methods: ['PUT'])]
    public function updateDataSetting(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');
        $result = [];
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $label = $dataSetting['label'];
        $this->updateMetaDataInfo($dataSetting);
        try {
            $isValidMapURL = $this->checkMapURLConnection($dataSetting);
            $isAddded = $this->dataSettings->updateDataSetting($dataSetting);

            if ($isAddded === true && $isValidMapURL === true) {
                $result['description'] = "Updated configuration for $label";
                $result['code'] = 200;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK);
            } else {
                $result['description'] = "Failed to update configuration for $label";
                $result['code'] = 500;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            $pattern = "/(?<=SQLSTATE\[HY\d{3}\]\s\[\d{4}\]).*/";
            $match = preg_match($pattern, $errMsg, $matchStr);
            if ($match) {
                $errMsg = $matchStr[0];
            }
            $result['description'] = "Failed to update  configuration for $label. $errMsg";
            $result['code'] = 500;
            $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->logger->error("Failed updating configuration", ["context" => (string) $e]);
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    function updateMetaDataInfo(&$dataSetting) {

        $enabled = null;
        $serviceType = null;
        if (($enabled = $dataSetting['mapInfo']['enabled'] ?? false) && ($serviceType = $dataSetting['mapInfo']['service']['name'] ?? '') === 'File') {
            //get the metadata update minZoom | maxZooom | add bounds | Url extension ?
            $mapServer = new \Server();
            $mapfolderPath = realpath($this->kernel->getProjectDir() . '/maps/');
            $mbtFile = $mapfolderPath . DIRECTORY_SEPARATOR . $dataSetting['mapInfo']['service']['filename'];
            $metaData = $mapServer->metadataFromMbtiles($mbtFile);
            //zoom information
            $minZoom = $metaData['minzoom'];
            $dataSetting['mapInfo']['service']['options']['minZoom'] = $metaData['minzoom'];
            $dataSetting['mapInfo']['service']['options']['maxZoom'] = $metaData['maxzoom'];
            //bounds
            $dataSetting['mapInfo']['service']['bounds'] = $metaData['bounds'];
        }
    }

    #[Route('/dataSettings/fileInfo', name: 'mapFileInfo', methods: ['GET'])]
            function getMapFileList(Request $request): CSProResponse {

        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');
        $mapfolderPath = realpath($this->kernel->getProjectDir() . '/maps');

        $mapFiles = glob($mapfolderPath . DIRECTORY_SEPARATOR . "*.mbtiles");
        foreach ($mapFiles as &$fileName) {
            $fileName = basename($fileName);
        }
        $response = new CSProResponse(json_encode($mapFiles, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/dataSettings/{fileName}/content', name: 'mapUpload', methods: ['PUT'], requirements: ['filePath' => '.+'])]
            function updateMapFileContent(Request $request, $fileName): CSProResponse {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');

        $fileManager = new FileManager();
        $fileManager->rootFolder = realpath($this->kernel->getProjectDir() . '/maps');
        $md5Content = $request->headers->get('Content-MD5');
        $contentLength = $request->headers->get('Content-Length');
        $content = $request->getContent();
        //var_dump($content);
        $response = null;
        if (!isset($md5Content) && isset($contentLength)) {
            $saveFile = $contentLength == strlen($content);
        } else {
            //echo 'generated md5 :' . md5($content);
            //echo '$md5Content :' .$md5Content;
            $saveFile = md5($content) === $md5Content;
        }

        if ($saveFile) {
            $invalidFileName = is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $fileName);
            if ($invalidFileName == true) {
                $response = new CSProResponse();
                $response->setError(400, 'file_save_error', 'Error writing file. Filename is a directory');
            } else {
                $fileInfo = $fileManager->putFile($fileName, $content);
                if (isset($fileInfo)) {
                    $response = new CSProResponse(json_encode($fileInfo, JSON_THROW_ON_ERROR));
                } else {
                    $this->logger->error('Internal error writing file' . $fileName);
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

    #[Route('/dataSettings/{dictionaryId}', name: 'deleteSetting', methods: ['DELETE'])]
            function deleteSetting(Request $request, $dictionaryId): Response {
        $this->denyAccessUnlessGranted('ROLE_SETTINGS_ALL');

        $result = [];
        try {
            $isDeleted = $this->dataSettings->deleteDataSetting($dictionaryId);

            if ($isDeleted) {
                $result['description'] = 'Deleted configuration. Dictionary Id: ' . $dictionaryId;
                $result['code'] = 200;
                $this->logger->debug($result['description']);
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK);
            } else {
                $result['description'] = 'Failed deleting configuration. Dictionary Id: ' . $dictionaryId;
                $result['code'] = 500;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception) {
            $result['description'] = 'Failed deleting configuration. Dictionary Id: ' . $dictionaryId;
            $result['code'] = 500;
            $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    private function checkMapURLConnection($dataSetting): bool {
        $e = null;
        $flag = false;
        try {
            $client = new Client();
            $mapInfo = $dataSetting['mapInfo'];
            $this->logger->debug('mapInfo: ' . print_r($mapInfo, true));
            if ($mapInfo['enabled'] === false) {
                $flag = true; //no need for verification
            } else {
                if ($mapInfo['service']['keyRequired']) {//only need to check if a key is required
                    $mapURL = $mapInfo['service']['testUrl'];
                    $key = rtrim($mapInfo['service']['options']['accessToken']);
                    $mapURL = str_replace('{access_token}', $key, rtrim($mapURL));
                    $response = $client->request('GET', rtrim($mapURL), ['verify' => false]);
                } else {
                    return true;
                }
                if ($response->getStatusCode() != 200) {
                    throw new \Exception("Failed to contact map server $mapURL : error " . $response->getStatusCode());
                } else {
                    if (trim($mapInfo['service']['name']) === 'Mapbox') {
                        $serverResponse = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                        if (isset($serverResponse['code']) && $serverResponse['code'] !== 'TokenValid') {
                            throw new \Exception("Failed to contact map server $mapURL : error " . $serverResponse['code']);
                        }
                    }
                    $flag = true;
                }
            }
        } catch (Exception) {
            throw new \Exception("Failed to contact map server $mapURL : " . $e->getMessage());
        }

        return $flag;
    }

}
