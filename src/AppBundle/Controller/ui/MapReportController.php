<?php

namespace AppBundle\Controller\ui;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\MapDataRepository;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\Data\DataSettings;

class MapReportController extends AbstractController implements TokenAuthenticatedController {

    private $mapDataRepository;

    public function __construct(private HttpHelper $client, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    //override the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->mapDataRepository = new MapDataRepository($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    #[Route('/map-report', name: 'map-report', methods: ['GET'])]
    public function viewMapReportListAction(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        return $this->render('mapReport.twig', []);
    }

    #[Route('/map-report/dictionary/ids', name: 'map_report_dictionary_ids', methods: ['GET'])]
    public function getMapReportIds(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        $dictionaryName = $request->get('dictionary');
        $ids = $request->get('ids');

        $idList = $this->mapDataRepository->getIdList($dictionaryName, $ids);

        $response = new Response(json_encode($idList, JSON_THROW_ON_ERROR), Response::HTTP_OK);
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }

    #[Route('/map-report/points', name: 'map_report_points', methods: ['GET'])]
    public function getMapReportPoints(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        $dictionaryName = $request->get('dictionary');

        $ids = $request->get('ids');
        $maxMapPoints = $this->getParameter('csweb_max_map_points');
        $mapPoints = $this->mapDataRepository->getMapDataPoints($dictionaryName, $ids, $maxMapPoints);

        $response = new Response(json_encode($mapPoints, JSON_THROW_ON_ERROR), Response::HTTP_OK);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/map-report/dictionaries/', name: 'map_report_dictionaries', methods: ['GET'])]
    public function getMapReportDictionariesList(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        // set the oauth token for api endpoint request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('GET', 'report-dictionaries', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
        ]);

        $reportDictionaryList = json_decode($apiResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $dataSettings = new DataSettings($this->pdo, $this->logger);
        $mapDataSettings = $dataSettings->getDataSettings();
        $mapReportDictionaryList = [];
        try {
            foreach ($mapDataSettings as $dataSetting) {
                //if map is enabled
                $dataSetting['mapInfo'] = isset($dataSetting['mapInfo']) ? json_decode($dataSetting['mapInfo'], true, 512, JSON_THROW_ON_ERROR) : null;
                if (isset($dataSetting['mapInfo']) && isset($dataSetting['mapInfo']['enabled']) && $dataSetting['mapInfo']['enabled'] === true && 0 < $dataSetting['processedCases']) {
                    // map config exists and at least one processed case exists
                    $key = array_search($dataSetting['name'], array_column($reportDictionaryList, 'dictionary_name'));
                    $reportDictionaryList[$key]['dataSetting'] = $dataSetting;
                    $mapReportDictionaryList[] = $reportDictionaryList[$key];
                }
            }
            $response = new Response(json_encode($mapReportDictionaryList, JSON_THROW_ON_ERROR));
        } catch (\Exception $e) {
            $this->logger->error('Failed getting list of dictionaries for map report', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting list of dictionaries for map report';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_dictionaries_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/map-report/marker/{dictName}/cases/{caseId}', methods: ['GET'])]
            function getCaseMarker(Request $request, $dictName, $caseId): Response {
        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        $result = [];
        // Set the oauth token for api endpoint request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //download case JSON
        $response = $this->client->request('GET', 'dictionaries/' . $dictName . '/cases/' . $caseId, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        try {
            $markerItemList = $this->mapDataRepository->getCaseMarkerItemList($dictName);
            $mapMarkerInfo = [];
            if (!empty($markerItemList)) {

                $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
                $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
                $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

                $dictionary = $dictionaryHelper->loadDictionary($dictName);
                $caseJSON = $response->getBody();
                $caseJSON = json_decode($caseJSON, true, 512, JSON_THROW_ON_ERROR);
                $mapMarkerInfo = $dictionaryHelper->formatMapMarkerInfo($dictionary, $caseJSON, $markerItemList);
            }
            $response = new Response(json_encode($mapMarkerInfo, JSON_THROW_ON_ERROR), Response::HTTP_OK);
            $response->headers->set('Content-Length', strlen($response->getContent()));

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case marker info', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting case marker info';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_case_marker_info_error', $result ['description']);
        }


        return $response;
    }

    #[Route('/map-report/{dictName}/cases/{caseId}', methods: ['GET'])]
            function getCase(Request $request, $dictName, $caseId): CSProResponse {
        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        $result = [];
        // Set the oauth token for api request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //upload dictionary
        $response = $this->client->request('GET', 'dictionaries/' . $dictName . '/cases/' . $caseId, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictName);
            $caseJSON = $response->getBody();
            $caseJSON = json_decode($caseJSON, true, 512, JSON_THROW_ON_ERROR);
            $caseHtml = $dictionaryHelper->formatCaseJSONtoHTML($dictionary, $caseJSON);

            $response = new CSProResponse($caseHtml);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $response->headers->set('Content-Type', 'text/html');
            $response->setCharset('utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case html', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting case html';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_case_html_error', $result ['description']);
        }


        return $response;
    }

    #[Route('/map-report/location-items/{dictionaryName}', name: 'report_dictionary_location', methods: ['GET'])]
            function getDictionaryLatLongList(Request $request, $dictionaryName): CSProResponse {
        $this->denyAccessUnlessGranted('ROLE_REPORTS_ALL');
        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
            $result = [];
            $result['gps'] = $dictionaryHelper->getPossibleLatLongItemList($dictionary);
            $result['metadata'] = $dictionaryHelper->getItemsForMapPopupDisplay($dictionary);

            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting report latitude and longitude item list', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting report latitude and longitude item list';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_lat_long_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
