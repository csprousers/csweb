<?php

namespace App\Controller\ui;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use App\Service\HttpHelper;
use App\Service\PdoHelper;
use App\CSPro\Data\DataSettings;
use App\CSPro\CSProResponse;
use GuzzleHttp\Client;
use App\CSPro\FileManager\CSProFileManager;
use App\Security\SettingsVoter;
use Symfony\Component\HttpKernel\KernelInterface;

require_once __DIR__ . '/../../../maps/server.php';

/**
 * Description of DataSettingsController
 *
 * @author savy
 */
class DataSettingsController extends AbstractController implements TokenAuthenticatedController {

    private $dataSettings;

    // Valid database name pattern: alphanumeric and underscores only
    private const SCHEMA_NAME_PATTERN = '/^[a-zA-Z0-9_]+$/';
    // Valid dictionary ID pattern: alphanumeric, underscores, hyphens only
    private const DICTIONARY_ID_PATTERN = '/^[a-zA-Z0-9_\-]+$/';
    // Safe filename pattern: alphanumeric, underscores, hyphens, dots  no path separators
    private const SAFE_FILENAME_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    public function __construct(private HttpHelper $client, private PdoHelper $pdo, private KernelInterface $kernel, private LoggerInterface $logger) {
        $this->kernel = $kernel;
    }

    // Override setContainer to get access to container parameters and initialize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->dataSettings = new DataSettings($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    #[Route('/dataSettings', name: 'dataSettings', methods: ['GET'])]
    public function viewDataSettingsAction(Request $request): Response {
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_READ);
        // Set the oauth token
        $dataSettings = $this->dataSettings->getDataSettings();
        $this->logger->debug('data settings ' . print_r($dataSettings, true));
        return $this->render('dataSettings.twig', ['dataSettings' => $dataSettings]);
    }

    #[Route('/getSettings', name: 'getSettings', methods: ['GET'])]
    public function getDataSettings(Request $request): Response {
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_READ);
//get data settings
        $dataSettings = $this->dataSettings->getDataSettings();
        $this->logger->debug('data settings ' . print_r($dataSettings, true));
        return $this->render('dataSettings.twig', ['dataSettings' => $dataSettings]);
    }

    #[Route('/addSetting', name: 'addSetting', methods: ['POST'])]
    public function addDataSetting(Request $request): Response {
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_WRITE);

        $result = [];
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        // Validate required fields before doing anything else
        $validationError = $this->validateDataSetting($dataSetting);
        if ($validationError !== null) {
            $result['description'] = $validationError;
            $result['code'] = 400;
            return new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_BAD_REQUEST);
        }

        $label = $dataSetting['label'];
        $this->updateMetaDataInfo($dataSetting);
        try {
            $isValidMapURL = $this->checkMapURLConnection($dataSetting);
            $isAdded = $this->dataSettings->addDataSetting($dataSetting);

            if ($isAdded === true && $isValidMapURL === true) {
                $result['description'] = "Added configuration for $label";
                $result['code'] = 200;
                $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK);
            } else {
                $result['description'] = "Failed to add configuration for $label";
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
            $result['description'] = "Failed to add configuration for $label. $errMsg";
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
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_WRITE);
        $result = [];
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        // Validate required fields before doing anything else
        $validationError = $this->validateDataSetting($dataSetting);
        if ($validationError !== null) {
            $result['description'] = $validationError;
            $result['code'] = 400;
            return new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_BAD_REQUEST);
        }

        $label = $dataSetting['label'];
        $this->updateMetaDataInfo($dataSetting);
        try {
            $isValidMapURL = $this->checkMapURLConnection($dataSetting);
            $isAdded = $this->dataSettings->updateDataSetting($dataSetting);

            if ($isAdded === true && $isValidMapURL === true) {
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
            $result['description'] = "Failed to update configuration for $label. $errMsg";
            $result['code'] = 500;
            $response = new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->logger->error("Failed updating configuration", ["context" => (string) $e]);
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * Validates required fields and formats for add/update data setting requests.
     * Returns an error message string on failure, or null if valid.
     */
    private function validateDataSetting(?array $dataSetting): ?string {
        if (!is_array($dataSetting)) {
            return "Invalid or missing request body.";
        }

        // --- Required field presence and non-empty checks ---
        $requiredFields = ['label', 'targetSchemaName', 'targetHostName', 'dbUserName'];
        foreach ($requiredFields as $field) {
            if (empty(trim($dataSetting[$field] ?? ''))) {
                $friendlyNames = [
                    'label' => 'Data label',
                    'targetSchemaName' => 'Database name',
                    'targetHostName' => 'Hostname',
                    'dbUserName' => 'Database username',
                ];
                return ($friendlyNames[$field] ?? $field) . " is required and cannot be empty.";
            }
        }

        // --- Schema name format: alphanumeric + underscores only ---
        $schemaName = trim($dataSetting['targetSchemaName']);
        if (!preg_match(self::SCHEMA_NAME_PATTERN, $schemaName)) {
            return "Database name may only contain letters, numbers, and underscores.";
        }

        // --- Hostname basic sanity: no spaces ---
        $hostname = trim($dataSetting['targetHostName']);
        if (preg_match('/\s/', $hostname)) {
            return "Hostname must not contain spaces.";
        }

        // --- mapInfo structure validation (only when map is enabled) ---
        $mapInfo = $dataSetting['mapInfo'] ?? null;
        if (!is_array($mapInfo)) {
            return "Missing or invalid map configuration.";
        }

        $mapEnabled = $mapInfo['enabled'] ?? false;
        if ($mapEnabled === true) {
            $service = $mapInfo['service'] ?? null;
            if (!is_array($service)) {
                return "Map is enabled but no service configuration was provided.";
            }

            $serviceName = $service['name'] ?? null;
            if (empty($serviceName)) {
                return "Map service name is required when map is enabled.";
            }

            $keyRequired = $service['keyRequired'] ?? false;
            if ($keyRequired) {
                // testUrl must exist if a key is required (used by checkMapURLConnection)
                if (empty($service['testUrl'] ?? '')) {
                    return "Map service is missing a test URL required for key validation.";
                }
                // accessToken must be present
                $accessToken = trim($service['options']['accessToken'] ?? '');
                if (empty($accessToken)) {
                    return "An access token is required for the selected map service.";
                }
            }

            // GPS fields must be present and different
            $latitude = $mapInfo['gps']['latitude'] ?? null;
            $longitude = $mapInfo['gps']['longitude'] ?? null;
            if ($latitude === null || $longitude === null) {
                return "Latitude and longitude fields are required when map is enabled.";
            }
            if ($latitude === $longitude) {
                return "Latitude and longitude items must be different.";
            }

            // If using file-based basemap, filename must be set
            if ($serviceName === 'File') {
                $filename = trim($service['filename'] ?? '');
                if (empty($filename)) {
                    return "A map file must be selected when using the File tile provider.";
                }
                // Validate the filename is safe (no path traversal)
                if (!preg_match(self::SAFE_FILENAME_PATTERN, $filename) || str_contains($filename, '..')) {
                    return "Invalid map filename.";
                }
            }
        }

        return null; // all good
    }

    function updateMetaDataInfo(&$dataSetting): void {
        $enabled = null;
        $serviceType = null;
        if (($enabled = $dataSetting['mapInfo']['enabled'] ?? false) && ($serviceType = $dataSetting['mapInfo']['service']['name'] ?? '') === 'File') {
            $mapServer = new \Server();
            $mapfolderPath = realpath($this->kernel->getProjectDir() . '/maps/');
            $mbtFile = $mapfolderPath . DIRECTORY_SEPARATOR . $dataSetting['mapInfo']['service']['filename'];
            $metaData = $mapServer->metadataFromMbtiles($mbtFile);
            $dataSetting['mapInfo']['service']['options']['minZoom'] = $metaData['minzoom'];
            $dataSetting['mapInfo']['service']['options']['maxZoom'] = $metaData['maxzoom'];
            //bounds
            $dataSetting['mapInfo']['service']['bounds'] = $metaData['bounds'];
        }
    }

    #[Route('/dataSettings/fileInfo', name: 'mapFileInfo', methods: ['GET'])]
            function getMapFileList(Request $request): CSProResponse {

        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_READ);
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
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_WRITE);

        // Validate filename: must end in .mbtiles, no path traversal, no unsafe characters
        if (
            !preg_match(self::SAFE_FILENAME_PATTERN, $fileName) ||
            str_contains($fileName, '..') ||
            strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'mbtiles'
        ) {
            $response = new CSProResponse();
            $response->setError(400, 'invalid_filename', 'Invalid file name. Only .mbtiles files with safe names are accepted.');
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        $fileManager = new CSProFileManager($this->logger);
        $fileManager->rootFolder = realpath($this->kernel->getProjectDir() . '/maps');
        $md5Content = $request->headers->get('Content-MD5');
        $contentLength = $request->headers->get('Content-Length');
        $content = $request->getContent();

        $response = null;
        if (!isset($md5Content) && isset($contentLength)) {
            $saveFile = $contentLength == strlen($content);
        } else {
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
                    $this->logger->error('Internal error writing file ' . $fileName);
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
        $this->denyAccessUnlessGranted(SettingsVoter::SETTINGS_WRITE);

        $result = [];
        // Validate dictionary ID format before using it
        if (empty($dictionaryId) || !preg_match(self::DICTIONARY_ID_PATTERN, $dictionaryId)) {
            $result['description'] = 'Invalid dictionary ID format.';
            $result['code'] = 400;
            return new Response(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_BAD_REQUEST);
        }

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
        $mapURL = '';
        $flag = false;
        try {
            $client = new Client();
            $mapInfo = $dataSetting['mapInfo'];
            $this->logger->debug('mapInfo: ' . print_r($mapInfo, true));

            if (($mapInfo['enabled'] ?? false) === false) {
                return true; // no need for verification
            }

            $service = $mapInfo['service'] ?? [];
            $keyRequired = $service['keyRequired'] ?? false;

            if (!$keyRequired) {
                return true;
            }

            // Both testUrl and accessToken are guaranteed present by validateDataSetting()
            $mapURL = $service['testUrl'];
            $key = rtrim($service['options']['accessToken']);
            $mapURL = str_replace('{access_token}', $key, rtrim($mapURL));
            $response = $client->request('GET', rtrim($mapURL), ['verify' => false]);

            if ($response->getStatusCode() != 200) {
                throw new \Exception("Failed to contact map server $mapURL : error " . $response->getStatusCode());
            }

            if (trim($service['name'] ?? '') === 'Mapbox') {
                $serverResponse = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (isset($serverResponse['code']) && $serverResponse['code'] !== 'TokenValid') {
                    throw new \Exception("Failed to contact map server $mapURL : error " . $serverResponse['code']);
                }
            }

            $flag = true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to contact map server $mapURL : " . $e->getMessage(), 0, $e);
        }

        return $flag;
    }

}
