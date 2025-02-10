<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Filesystem\Filesystem;
use AppBundle\CSPro\UploadCasesJsonListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\DictionarySchemaHelper;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\Data\BinaryJSONConverter;
use AppBundle\CSPro\Data\CSProResourceBuffer;
use AppBundle\Security\DictionaryVoter;
use AppBundle\CSPro\Dictionary\JsonDictionaryParser;
use AppBundle\CSPro\Dictionary\Parser;

class DictionaryController extends AbstractController implements ApiTokenAuthenticatedController {

    private $dictHelper;
    private $serverDeviceId;

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private TokenStorageInterface $tokenStorage, private LoggerInterface $logger) {
        
    }

    //overrider the setcontainer to get access to container parameters and initiailize the dictionary helper
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
        $this->serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
        $this->dictHelper = new DictionaryHelper($this->pdo, $this->logger, $this->serverDeviceId);
        return parent::setContainer($container);
    }

    #[Route('/dictionaries/', methods: ['GET'])]
            function getDictionaryList(Request $request): CSProResponse {
        $stm = 'SELECT dictionary_name as name, dictionary_label as label
		FROM cspro_dictionaries';
        $result = $this->pdo->fetchAll($stm);

        foreach ($result as &$row) {
            $table = $row ['name'];
            if ($this->dictHelper->tableExists($table)) {
                $stm = 'SELECT COUNT(*) as caseCount FROM ' . $table . ' WHERE deleted = 0';
                $row ['caseCount'] = (int) $this->pdo->fetchValue($stm);
            } else {
                $row ['caseCount'] = 0;
            }
        }
        unset($row);
        $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }

    #[Route('/dictionaries/', methods: ['POST'])]
            function addDictionary(Request $request): CSProResponse {
        ///add dictionary only if permitted
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARY_OPERATIONS);

        $dictContent = $request->getContent();
        $response = new CSProResponse ();

        if (JsonDictionaryParser::isValidJSON($dictContent)) {
            $parser = new JsonDictionaryParser();
        } else {
            $parser = new Parser();
        }
        try {
            $dict = $parser->parseDictionary($dictContent);
        } catch (\Exception $e) {
            $response->setError(400, 'dictionary_invalid', $e->getMessage());
            $response->setStatusCode(CSProResponse::HTTP_BAD_REQUEST);
            return $response;
        }

        $dictName = $dict->getName();

        if ($dict->hasBinaryItems()) {
            //create the dictionary folder for storing binary items in files folder
            $binaryItemsDirectory = $this->dictHelper->getDictionaryBinaryItemsFolder($dictName, $this->getParameter('csweb_api_files_folder'));
            if (!is_dir($binaryItemsDirectory)) {
                $this->logger->info('Creating binary items directory: ' . $binaryItemsDirectory);
                if (!mkdir($binaryItemsDirectory, 0755, true)) {
                    $this->logger->error('Unable to create binary items directory: ' . $binaryItemsDirectory);
                    $response = new CSProResponse();
                    $response->setError(403, 'dictionary_add_failed', 'Unable to create binary items directory :' . $binaryItemsDirectory);
                    return $response;
                }
            }
        }
        if ($this->dictHelper->dictionaryExists($dictName)) {
            $this->dictHelper->updateExistingDictionary($dict, $dictContent, $response);
        } else {
            $this->dictHelper->createDictionary($dict, $dictContent, $response);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/dictionaries/{dictName}/syncspec', methods: ['GET'])]
            function getDictionarySyncSpec(Request $request, $dictName): CSProResponse {
        $this->denyAccessUnlessGranted(DictionaryVoter::DATA_DOWNLOAD);

        $this->dictHelper->checkDictionaryExists($dictName);
        $syncURL = $this->getParameter('cspro_rest_api_url');
        $csproVersion = $this->getParameter('cspro_version');
        $csproVersion = substr($csproVersion, 0, 3); //get {Major}.{Minor} version
        $syncSpec = chr(239) . chr(187) . chr(191); //BOM
        $syncSpec .= "[Run Information]" . "\r\n";
        $syncSpec .= "Version=" . $csproVersion . "\r\n";
        $syncSpec .= "AppType=Sync" . "\r\n";
        $syncSpec .= "\r\n";
        $syncSpec .= "[ExternalFiles]" . "\r\n";
        $syncSpec .= strtoupper($dictName) . '=' . strtolower($dictName) . '.csdb' . "\r\n";
        $syncSpec .= "\r\n";
        $syncSpec .= "[Parameters]" . "\r\n";
        $syncSpec .= "SyncDirection=Get" . "\r\n";
        $syncSpec .= "SyncType=CSWeb" . "\r\n";
        $syncSpec .= "SyncUrl=" . $syncURL . "\r\n";
        $syncSpec .= "Silent=No" . "\r\n";

        $response = new CSProResponse($syncSpec);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        $response->headers->set('Content-Type', 'text/plain');
        $response->setCharset('utf-8');
        $filename = strtolower($dictName) . ".pff";
        $contentDisposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $contentDisposition);

        return $response;
    }

    #[Route('/dictionaries/{dictName}', methods: ['GET'])]
            function getDictionary(Request $request, $dictName): CSProResponse {
        $stm = 'SELECT dictionary_full_content FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = ['dictName' => ['dictName' => $dictName]];
        $dictText = $this->pdo->fetchValue($stm, $bind);
        if ($dictText == false) {
            $response = new CSProResponse();
            $response->setError(404, "Dictionary {$dictName} does not exist");
            $response->headers->set('Content-Length', strlen($response->getContent()));
        } else {
            $response = new CSProResponse($dictText);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $response->headers->set('Content-Type', 'text/plain');
            $response->setCharset('utf-8');
        }

        return $response;
    }

    private function processDeleteDictionary(string $dictName, bool $bDataOnly = false): CSProResponse {

        $bind = [];
        $result = [];
        $this->dictHelper->checkDictionaryExists($dictName);
        $this->logger->notice('Deleting dictionary: ' . $dictName);
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARY_OPERATIONS);

        $strMsg = $bDataOnly ? "dictionary" : "dictionary data";
        try {
            // return the cases that are >old revision# and <> new revision#
            $this->pdo->beginTransaction();

            // Get the dictionary ID from the dictionary table;
            $stm = $stm = 'SELECT id FROM cspro_dictionaries  WHERE dictionary_name = :dictName';
            $bind = [];
            $bind['dictName'] = $dictName;

            // delete data in relational database
            $dictionarySchemaHelper = new DictionarySchemaHelper($dictName, $this->pdo, $this->logger);
            $dictionarySchemaHelper->regenerateSchema();

            $this->logger->notice('Deleted data in relational (processed cases) database for dictionary: ' . $dictName);

            $dictID = $this->pdo->fetchValue($stm, $bind);

            // delete sync history
            $stm = $stm = 'DELETE FROM `cspro_sync_history` WHERE dictionary_id=:dictID';
            $bind = [];
            $bind['dictID'] = $dictID;

            $deletedSyncHistoryCount = $this->pdo->fetchAffected($stm, $bind);

            // delete dictionary from cspro_dictionaries table
            if ($bDataOnly) {// delete content when flag is set to data only
                // delete data for dictionary_notes TABLE
                $this->logger->notice('Deleting dictionary data for: ' . $dictName);

                $stm = 'DELETE FROM ' . $dictName . '_notes;';
                $result = $this->pdo->query($stm);
                $this->logger->notice('Deleted data for dictionary notes table: ' . $dictName . '_notes');

                //delete case binary data
                $stm = 'DELETE FROM ' . $dictName . '_case_binary_data;';
                $result = $this->pdo->query($stm);
                $this->logger->notice('Deleted data for dictionary notes table: ' . $dictName . '_case_binary_data');

                // delete data for dictionary TABLE
                $stm = 'DELETE FROM ' . $dictName;
                $result = $this->pdo->query($stm);
                $this->logger->notice('Deleted data for dictionary table: ' . $dictName);
            } else {// drop all the associated tables when data and dictionary are to be deleted
                $this->logger->notice('Deleting dictionary: ' . $dictName);

                $stm = 'DELETE	FROM cspro_dictionaries  WHERE dictionary_name = :dictName';
                unset($bind);
                $bind ['dictName'] = $dictName;
                $sth = $this->pdo->prepare($stm);
                $sth->execute($bind);

                // DROP TABLE dictionary_notes;
                $stm = 'DROP TABLE IF EXISTS ' . $dictName . '_notes;';
                $result = $this->pdo->query($stm);

                // DROP TABLE dictionary case binary data 
                $stm = 'DROP TABLE IF EXISTS ' . $dictName . '_case_binary_data;';
                $result = $this->pdo->query($stm);

                $this->logger->notice('Dropped dictionary notes table: ' . $dictName . '_notes');
                // DROP TABLE dictionary;
                $stm = 'DROP TABLE IF EXISTS ' . $dictName;
                $result = $this->pdo->query($stm);

                $this->logger->notice('Dropped dictionary table: ' . $dictName);
            }

            //Some databases, including MySQL, automatically issue an implicit COMMIT when a database definition language (DDL) statement such as DROP TABLE or CREATE TABLE is issued within a transaction.
            // The implicit COMMIT will prevent you from rolling back any other changes within the transaction boundary.
            if ($this->pdo->inTransaction())
                $this->pdo->commit();

            //remove binary-items directory if it exists. 
            $binaryItemsDirectory = $this->dictHelper->getDictionaryBinaryItemsFolder($dictName, $this->getParameter('csweb_api_files_folder'));
            $filesystem = new Filesystem();
            if ($filesystem->exists($binaryItemsDirectory)) {
                try {
                    $filesystem->remove($binaryItemsDirectory);
                    if ($bDataOnly) {
                        $filesystem->mkdir($binaryItemsDirectory, 0755); // recreate the directory
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Failed deleting binary items directory: $binaryItemsDirectory " . $e->getMessage());
                }
            }
            unset($result);
            $result ['code'] = 200;
            $result ['description'] = 'Success';
            $response = new CSProResponse(json_encode($result));
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $this->logger->notice("Deleted  $strMsg: $dictName");
        } catch (\Exception $e) {
            $this->logger->error("Failed deleting $strMsg $dictName", ["context" => (string) $e]);
            $this->pdo->rollBack();

            $response = new CSProResponse ();
            $response->setError(500, 'dictionary_delete_error', "Failed deleting $strMsg");
            $response->headers->set('Content-Length', strlen($response->getContent()));
        }

        return $response;
    }

    #[Route('/dictionaries/{dictName}/data', methods: ['DELETE'])]
            function deleteDictionaryData(Request $request, $dictName): CSProResponse {
        return $this->processDeleteDictionary($dictName, true);
    }

    #[Route('/dictionaries/{dictName}', methods: ['DELETE'])]
            function deleteDictionary(Request $request, $dictName): CSProResponse {
        return $this->processDeleteDictionary($dictName, false);
    }

    // Syncs

    #[Route('/dictionaries/{dictName}/syncs', methods: ['GET'])]
            function getSyncHistory(Request $request, $dictName): CSProResponse {
        $from = $request->get('from');
        $to = $request->get('to');
        $device = $request->get('device');
        $limit = $request->get('limit');
        $offset = $request->get('offset');

        return new CSProResponse('How about implementing getSyncHistory as a GET method ?');
    }

    #[Route('/dictionaries/{dictName}/syncs', methods: ['POST'])]
            function syncCases(Request $request, $dictName): CSProResponse {
        return new CSProResponse('Method Not Allowed', CSProResponse::HTTP_METHOD_NOT_ALLOWED);
    }

    // get cases

    #[Route('/dictionaries/{dictName}/cases', methods: ['GET'])]
            function getCases(Request $request, $dictName): Response {

        $maxScriptExecutionTime = $this->getParameter('csweb_api_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);

        $dictId = $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_download', $dictName);

        //NOTE: For performance issues if a dictionary has binary items irrespective of whether the cases have binary content the response 
        //will always be binary json format. Also, the range count in this case may not be correct as the callback will limit after a preset 
        //limit for the total binary content size is reached. This should not impact the client as the client will request from the last guid it receieved
        $isBinaryJSON = false;
        $dict = $this->dictHelper->loadDictionary($dictName);
        if ($dict->hasBinaryItems()) {
            $isBinaryJSON = true;
        }

        $response = new CSProResponse ();

        $universe = $request->headers->get('x-csw-universe');
        $universe = trim($universe, '"');
        $excludeRevisions = $request->headers->get('x-csw-exclude-revisions');

        //getCases Has eTag and deviceName
        // Check x-csw-if-revision-exists   header to see if this an update to a previous sync
        $lastRevision = null;
        $lastRevision = $request->headers->get('x-csw-if-revision-exists');
        $deviceId = $request->headers->get('x-csw-device');
        if (empty($lastRevision)) {
            $lastRevision = 0;
        }
        //get the custome headers 
        $startAfterGuid = $request->headers->get('x-csw-case-range-start-after');
        $rangeCount = $request->headers->get('x-csw-case-range-count');

        if (!empty($rangeCount)) {
            $rangeCount = trim($rangeCount, ' /');
            $this->logger->debug('range count' . $rangeCount);
            if (!is_numeric($rangeCount) || $rangeCount < 0) {
                $this->logger->error('Invalid range count' . $rangeCount);
                $response->setError(400, 'invalid_range_count', 'Invalid range count header');
                $response->headers->set('Content-Length', strlen($response->getContent()));
                return $response;
            }
            $rangeCount = (int) $rangeCount;
        }

        //Get the maxFileRevision
        $maxRevision = $this->getMaxRevisionNumber();
        if (!$maxRevision)
            $maxRevision = 1;

        if ($maxRevision < $lastRevision) {
            $response->headers->set('ETag', $maxRevision);
            $response = new CSProResponse(json_encode([], JSON_FORCE_OBJECT), CSProResponse::HTTP_PRECONDITION_FAILED);
            return $response;
        }
        //Get the cases requested, if it is less than the number of cases to be sent set the response code to 206
        //otherwise set to 200 
        //set the header content with #cases/#totalCases.
        #set the ETag with the new maxFileRevision 
        $response = new StreamedResponse();
        //get max revision for chunk
        $bind = [];
        $bind['lastRevision'] = $lastRevision;
        $bind['maxRevision'] = $maxRevision;
        $strWhere = '';
        $universe = $request->headers->get('x-csw-universe');
        $universe = trim($universe, '"');

        $startAfterGuid = empty($startAfterGuid) ? $startAfterGuid = '' : $startAfterGuid;

        if (!empty($startAfterGuid)) {
            $strWhere = ' WHERE ((revision = :lastRevision AND  guid > (UNHEX(REPLACE(:case_guid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
            $bind['case_guid'] = $startAfterGuid;
        } else {
            $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';
        }


        if (!empty($excludeRevisions)) {
            $arrExcludeRevisions = explode(',', $excludeRevisions);
            $strWhere .= ' AND revision NOT IN (:exclude_revisions) ';
            $bind['exclude_revisions'] = $arrExcludeRevisions;
        }
        //universe condition
        $strUniverse = '';
        if (!empty($universe)) {
            $strUniverse = ' AND (caseids LIKE :universe) ';
            $universe .= '%';
            $bind['universe'] = $universe;
        }

        $maxRevisionForChunk = $maxRevision;
        if (isset($rangeCount) && $rangeCount > 0) {
            $strChunkQuery = '( SELECT revision from ' . $dictName;
            $stm = $strChunkQuery . $strWhere . $strUniverse . ' ORDER BY revision LIMIT :rangeCount ) AS T1';
            $stm = 'SELECT max(revision) FROM ' . $stm;
            $bind['rangeCount'] = $rangeCount;
            $this->logger->debug('max revision for chunk: ' . $stm);
            $maxRevisionForChunk = $this->getMaxRevisionNumberForChunk($stm, $bind);
            unset($bind['rangeCount']);
            if ($maxRevisionForChunk <= 0)
                $maxRevisionForChunk = $maxRevision; //set it to the max revision of the full selection.
            $this->logger->debug('max revision for chunk: ' . $maxRevisionForChunk);
        }

        $caseCount = $this->getCaseCount($dictName, $universe, $excludeRevisions, $lastRevision, $maxRevision, $startAfterGuid);

        $dictController = $this;
        //archive any binary syncs sent to this device to the binary sync history archive table 
        if ($isBinaryJSON) {
            $this->dictHelper->archiveBinarySyncHistoryEntries($dictId, $deviceId, $lastRevision, $startAfterGuid);
        }


        $response->setCallback(function () use ($request, $isBinaryJSON, $deviceId, $strWhere, $strUniverse, $bind, $maxRevisionForChunk, $rangeCount, $caseCount, $dictName, $dictController) {
            $casesJSONStream = fopen('php://temp', 'w+b');
            $caseBinaryItemMap = array();
            $binaryItemsDirectory = $this->dictHelper->getDictionaryBinaryItemsFolder($dictName, $this->getParameter('csweb_api_files_folder'));
            $maxPacketSize = $this->getParameter('csweb_max_sync_download_packet_size');
            $caseJSONInfo = $dictController->dictHelper->writeCasesJSONToStream($casesJSONStream, $request, $dictName, $deviceId, $binaryItemsDirectory, $bind, $strWhere,
                    $strUniverse, $maxRevisionForChunk, $rangeCount, $isBinaryJSON, $caseBinaryItemMap, $maxPacketSize);
            //compression will be taken care of by apache 
            if ($isBinaryJSON) {
                $outputStream = fopen('php://temp', 'w+b');
                $outputResourceBuffer = new CSProResourceBuffer($outputStream); //destructor closes the stream
                $binaryJsonConverter = new BinaryJSONConverter($this->logger);
                $binaryJsonConverter->writeBinaryJson($outputResourceBuffer, $casesJSONStream, $caseBinaryItemMap, $binaryItemsDirectory);
                ftruncate($casesJSONStream, 0);
                $outputResourceBuffer->copyToStream($casesJSONStream, null, 0);
            }
            rewind($casesJSONStream);

            //set common headers for binaryJSON and JSON format content
            $strRangeHeader = $caseJSONInfo['totalCases'] . '/' . $caseCount;
            header('x-csw-case-range-count:' . $strRangeHeader);
            header('ETag:' . $caseJSONInfo['maxRevisionForChunk']);
            header('x-csw-chunk-max-revision:' . $caseJSONInfo['maxRevisionForChunk']); //nginx  strips Etag, now using custom header
            if ($caseJSONInfo['totalCases'] < $caseCount) { //sending partial content
                header('HTTP/1.1 206 Partial Content');
            } else {//sending all the cases
                header('HTTP/1.1 200 OK');
            }
            //end common headers
            //send the content to the client in chunks if binary json if not just write out entire stream contents to the php://output stream 
            $outputStream = fopen('php://output', 'wb');
            if ($isBinaryJSON) {
                $fstats = fstat($casesJSONStream);
                $length = $fstats['size'];
                //set the headers correctly for binary json 
                header('Content-Type: application/octet-stream');
                // insert a row into the sync history with the new version
                //SynchHistoryEntry out of transaction to prevent dead locks
                $userName = $dictController->tokenStorage->getToken()->getUserIdentifier();
                $lastCaseID = isset($caseJSONInfo['lastCaseId']) ? $caseJSONInfo['lastCaseId'] : "";
                $lastCaseRevision = isset($caseJSONInfo['maxRevisionForChunk']) ? $caseJSONInfo['maxRevisionForChunk'] : 0;
                $currentRevisionNumber = $this->dictHelper->addSyncHistoryEntry($deviceId, $userName, $dictName, 'get', $lastCaseRevision, $lastCaseID, $strUniverse);
                if ($isBinaryJSON && count($caseBinaryItemMap) > 0) {
                    $downloadedBinaryItemMd5s = array();
                    foreach ($caseBinaryItemMap as $binaryItems) {
                        foreach ($binaryItems as $binaryItem) {
                            $downloadedBinaryItemMd5s[] = $binaryItem['signature'];
                        }
                    }
                    $this->dictHelper->addBinarySyncHistoryEntry($dictName, $downloadedBinaryItemMd5s, $currentRevisionNumber);
                }

                //$byteSize =  stream_copy_to_stream($casesJSONStream, $outputStream);
                /* rewind($casesJSONStream);
                  $temp = tempnam($binaryItemsDirectory, 'TMP_');
                  $handle = fopen($temp, "w+b");
                  $byteSize = stream_copy_to_stream($casesJSONStream, $handle);
                  $fstats = fstat($handle);
                  $length = $fstats['size'];
                  fclose($handle);
                  fclose($casesJSONStream); */
                $chunkSize = 8 * 1024; //source code below from BinaryFileResponse::sendContent
                try {
                    while ($length && !feof($casesJSONStream)) {
                        $read = ($length > $chunkSize) ? $chunkSize : $length;
                        $length -= $read;
                        stream_copy_to_stream($casesJSONStream, $outputStream, $read);
                        if (connection_aborted()) {
                            break;
                        }
                    }
                } finally {
                    fclose($casesJSONStream);
                }
            } else {
                header('Content-Type: application/json');
                stream_copy_to_stream($casesJSONStream, $outputStream);
                fclose($casesJSONStream);
            }
        }
        );
        return $response;
    }

    // Add or update cases
    #[Route('/dictionaries/{dictName}/cases', methods: ['POST'])]
            function addOrUpdateCases(Request $request, $dictName): CSProResponse {
        $result = [];
        $response = null;
        $maxScriptExecutionTime = $this->getParameter('csweb_api_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);

        $dict = $this->dictHelper->loadDictionary($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_upload', $dictName);

        $params = [];
        $content = $request->getContent();

        $json_size = strlen($content);
        $useParser = $isBinaryJSON = false;
        $userName = $this->tokenStorage->getToken()->getUserIdentifier();
        $this->logger->debug("JSON payload size $json_size");
        if (!empty($content)) {
            $stream = $caseJSONStream = null;
            $zipFilterStream = fopen('php://temp', 'w+b');
            if ($request->headers->get('Content-Encoding') == 'gzip') {
                $this->logger->debug('Using stream filter to decompress sync data');
                stream_filter_append($zipFilterStream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15]);  // window of 15 for RFC1950 format which is what client sends                
            }
            fwrite($zipFilterStream, $content);
            rewind($zipFilterStream);
            //*DO NOT CHANGE* code below - bug in php - stream with filters gets strange results when doing fseek. Copying the contents to a regular stream
            $stream = fopen('php://temp', 'w+b');
            stream_copy_to_stream($zipFilterStream, $stream);
            fclose($zipFilterStream);
            rewind($stream);
            $signature = fread($stream, strlen(BinaryJSONConverter::BINARY_CASE_HEADER));
            $isBinaryJSON = BinaryJSONConverter::IsBinaryJSON($signature);
            $content = null;
            $useParser = true;
            rewind($stream);
            $syncCasesListener = new UploadCasesJsonListener($this->pdo, $this->dictHelper, $this->logger, $request, $userName, $dictName, $isBinaryJSON);
            try {//optimize for memory usage
                $this->pdo->beginTransaction();
                $caseJSONStream = $stream;
                if ($isBinaryJSON) {
                    $caseJSONStream = fopen('php://temp', 'w+b');
                    $inputResourceBuffer = new CSProResourceBuffer($stream); //destructor closes the stream
                    $binaryJsonConverter = new BinaryJSONConverter($this->logger);
                    $binaryJsonConverter->readCasesJsonFromBinaryToStream($inputResourceBuffer, $caseJSONStream);
                    //write binary items to disc
                    $binaryItemsDirectory = $this->dictHelper->getDictionaryBinaryItemsFolder($dictName, $this->getParameter('csweb_api_files_folder'));
                    $md5Signatures = $binaryJsonConverter->readBinaryCaseItemsNSave($inputResourceBuffer, $binaryItemsDirectory);
                    $syncCasesListener->setUploadedBinaryCaseItems($md5Signatures);
                }
                //set case json stream parser to parse binary cases and write to DB
                $parser = new \JsonStreamingParser\Parser($caseJSONStream, $syncCasesListener);
                $syncCasesListener->setParser($parser);
                $parser->parse();
                fclose($caseJSONStream);
            } catch (\Exception $e) {
                if ($useParser)
                    fclose($caseJSONStream);
                $this->logger->error('Failed Uploading Cases to dictionary: ' . $dictName, ["context" => (string) $e]);
                $this->pdo->rollBack();
                //delete the added sync history entry when rolled back
                $this->dictHelper->deleteSyncHistoryEntry($syncCasesListener->currentRevisionNumber);

                $response = new CSProResponse ();
                $response->setError(500, 'upload_cases_error', 'Failed uploading cases');
                $response->headers->set('Content-Length', strlen($response->getContent()));
                return $response;
            }
            $response = $syncCasesListener->getResponse();
            if ($response == null) {
                $response = new CSProResponse ();
                $this->logger->error('Failed Uploading Cases to dictionary: response from listener is empty');
                $response->setError(500, 'upload_cases_error', 'Failed syncing cases');
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
            return $response;
        } else {
            $this->logger->error('Request content is Empty. Invalid sync request: ' . $dictName);
            $result ['code'] = 400;
            $result ['description'] = 'Invalid upload request';
            $response->setError($result ['code'], 'upload_cases_error', $result ['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }
    }

    // Get a case
    #[Route('/dictionaries/{dictName}/cases/{caseId}', methods: ['GET'])]
            function getCase(Request $request, $dictName, $caseId): CSProResponse {
        $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_download', $dictName);
        try {
            // the statement to prepare
            $stm = "SELECT LCASE(CONCAT_WS('-', LEFT(HEX(guid), 8), MID(HEX(guid), 9,4), MID(HEX(guid), 13,4), MID(HEX(guid), 17,4), RIGHT(HEX(guid), 12))) as id,
				questionnaire as data, caseids, label, deleted,verified,
				partial_save_mode, partial_save_field_name, partial_save_level_key, 
				partial_save_record_occurrence, partial_save_item_occurrence, partial_save_subitem_occurrence,
				clock
				FROM " . $dictName . ' WHERE guid =(UNHEX(REPLACE(:case_guid' . ',"-","")))';

            $bind = [];
            $bind['case_guid'] = $caseId;

            $result = $this->pdo->fetchAll($stm, $bind);
            // getCaseNotes from the notes table and add to each case
            // getCaseNotes assumes caseid guids are sorted in asc - the default order by for mysql
            $this->dictHelper->getCaseNotes($result, $dictName . '_notes');
            $this->dictHelper->prepareResultSetForJSON($result);
            if (!$result) {
                $response = new CSProResponse ();
                $response->setError(404, 'case_not_found', 'Case not found');
            } else {
                $resultCase = $result [0];
                $response = new CSProResponse(json_encode($resultCase, JSON_THROW_ON_ERROR));
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case from dictionary: ' . $dictName, ["context" => (string) $e]);
            $response = new CSProResponse ();
            $response->setError(500, 'failed_get_case', 'Failed getting case');
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // Update a case. Body is required to be a json array with single element 
    #[Route('/dictionaries/{dictName}/cases/{caseId}', methods: ['PUT'])]
            function updateCase(Request $request, $dictName, $caseId): CSProResponse {
        if (!$this->dictHelper->checkCaseExists($dictName, $caseId)) {
            $response = new CSProResponse ();
            $response->setError(404, 'case_not_found', 'Case not found');
            return $response;
        }
        //NOTE: if multiple cases are sent this will add or update those cases in the case json array.
        return $this->addOrUpdateCases($request, $dictName);
    }

    #[Route('/dictionaries/{dictName}/cases/{caseId}', methods: ['DELETE'])]
            function deleteCase(Request $request, $dictName, $caseId): CSProResponse {
        $result = [];
        $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_upload', $dictName);
        // insert a row into the sync history with the new revision
        $userName = $this->tokenStorage->getToken()->getUserIdentifier();
        $deviceId = $request->headers->get('x-csw-device');
        $currentRevisionNumber = $this->dictHelper->addSyncHistoryEntry($deviceId, $userName, $dictName, 'put');
        try {
            $this->pdo->beginTransaction();

            // the statement to prepare
            $stm = 'UPDATE ' . $dictName . ' SET
					deleted = 1,
					revision = (SELECT IFNULL(MAX(revision),0) from cspro_sync_history WHERE device = :deviceId and dictionary_id = (SELECT id  FROM cspro_dictionaries WHERE dictionary_name = :dictName)) ' . ' WHERE guid = (UNHEX(REPLACE(:case_guid' . ',"-","")))';
            $bind = [];
            $bind['deviceId'] = $deviceId;
            $bind['case_guid'] = $caseId;
            $bind['dictName'] = $dictName;

            $row_count = $this->pdo->fetchAffected($stm, $bind);

            if ($row_count == 1) {
                $this->pdo->commit();
                $result ['code'] = 200;
                $result ['description'] = 'Success';
                $response = new CSProResponse(json_encode($result));
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                $response = new CSProResponse ();
                $response->setError(404, 'case_not_found', 'Case not found');
            }
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            //delete the added sync history entry when rolled back
            $this->dictHelper->deleteSyncHistoryEntry($currentRevisionNumber);

            $this->logger->error('Failed deleting case in dictionary: ' . $dictName, ["context" => (string) $e]);
            $response = new CSProResponse ();
            $response->setError(404, 'failed_insert_case', $e->getMessage());
        }

        return $response;
    }

    function getMaxRevisionNumber() {
        try {
            //returns the max revision in the cspro_sync_history table
            //may not match the max revision of the dictionary cases table 
            $stm = 'SELECT max(revision)  FROM  cspro_sync_history';
            $maxRevison = (int) $this->pdo->fetchValue($stm);
            return $maxRevison;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getMaxRevisionNumber ', 0, $e);
        }
    }

    function getMaxRevisionNumberForChunk($stm, $bind) {
        try {
            $maxRevison = (int) $this->pdo->fetchValue($stm, $bind);
            return $maxRevison;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getMaxRevisionNumberForChunk ', 0, $e);
        }
    }

    function getCaseCount($dictName, $universe, $excludeRevisions, $lastRevision, $maxRevision, $startAfterGuid) {
        try {
            if (empty($maxRevision)) {
                throw new \Exception('Failed in getCaseCount ' . $dictName . 'Expecting maxRevision to be set.');
            }
            $bind = [];

            $lastRevision = empty($lastRevision) ? 0 : $lastRevision;
            $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';

            $startAfterGuid = empty($startAfterGuid) ? $startAfterGuid = '' : $startAfterGuid;
            if (!empty($startAfterGuid)) {
                $strWhere = ' WHERE ((revision = :lastRevision AND  guid > (UNHEX(REPLACE(:case_guid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
                $bind['case_guid'] = $startAfterGuid;
            } else {
                $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';
            }

            $bind['lastRevision'] = $lastRevision;
            $bind['maxRevision'] = $maxRevision;

            if (!empty($excludeRevisions)) {
                $arrExcludeRevisions = explode(',', $excludeRevisions);
                $strWhere .= ' AND revision NOT IN (:exclude_revisions) ';
                $bind['exclude_revisions'] = $arrExcludeRevisions;
            }

            if (!empty($universe)) {
                $strWhere .= ' AND (caseids LIKE :universe) ';
                $universe .= '%';
                $bind['universe'] = $universe;
            }

            $stm = 'SELECT count(*)  FROM ' . $dictName . $strWhere;

            return (int) $this->pdo->fetchValue($stm, $bind);
        } catch (\Exception $e) {
            throw new \Exception('Failed in getCaseCount ' . $dictName, 0, $e);
        }
    }

}
