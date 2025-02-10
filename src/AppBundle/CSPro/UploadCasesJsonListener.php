<?php

namespace AppBundle\CSPro;

use JsonStreamingParser\Listener\ListenerInterface;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\CSPro\CSProResponse;
use JsonStreamingParser\Parser;
use AppBundle\CSPro\CSProJsonValidator;

/**
 * This basic CasesJSON implementation of a listener simply constructs an in-memory
 * representation of the JSON document at the second level,  a single case will be kept in memory
 *  rather than the whole case array for chunking. The call back function can then stash the cases
 *  until a chunk is accumulated and then process for inserts
 */
class UploadCasesJsonListener implements ListenerInterface {

    public const CHUNK_SIZE = 500;

    protected $cases; //used for processing insert or update without using parsing
    protected $stack;
    protected $key;
    public $currentRevisionNumber;
    protected $response;
    protected $device;
    protected $lastRevision;
    protected $colNames;
    protected $dict;
    protected $stopProcessing = false;
    protected $parser = null;
    protected $initialized = false;
// Level is required so we know how nested we are.
    protected $level;
    protected $caseBinaryItems;
    protected $uploadedBinaryCaseItems;

    /**
     *
     */
    public function __construct(protected $pdo, protected $dictHelper, protected $logger, protected Request $request, protected $userName, protected $dictName, protected $isBinaryJSON, protected $jsonCasesBody = null) {
        $this->cases = [];
        $this->lastRevision = null;
        $this->colNames = null;
        $this->currentRevisionNumber = null;
        $this->stopProcessing = false;
        $this->response = null;
        $this->initialized = false;
    }

    public function setResponse(CSProResponse $response) {
        $this->response = $response;
        $response->headers->set('Content-Length', strlen($response->getContent()));
    }

    public function setUploadedBinaryCaseItems($md5Signatures) {
        $this->uploadedBinaryCaseItems = $md5Signatures;
    }

    public function getResponse() {
        return $this->response;
    }

    public function setParser(Parser $parser) {
        $this->parser = $parser;
    }

    public function stopProcessing() {
        $this->stopProcessing = true;
        if ($this->parser)
            $this->parser->stop();
    }

    public function initUploadCases() {
        $this->initialized = true;
        $this->logger->debug('uploading cases!!!');

        $this->device = $this->request->headers->get('x-csw-device');

        $this->dict = $this->dictHelper->loadDictionary($this->dictName);

        $this->colNames = ['questionnaire', 'caseids', 'label', 'revision', 'deleted', 'verified', 'partial_save_mode', 'partial_save_field_name', 'partial_save_level_key', 'partial_save_record_occurrence', 'partial_save_item_occurrence', 'partial_save_subitem_occurrence', 'clock'];

// Check x-csw-if-revision-exists header to see if this an update to a previous upload
        $this->lastRevision = null;
        $lastUploadETag = $this->request->headers->get('x-csw-if-revision-exists');
        $this->logger->debug("Check etag $lastUploadETag");
        if ($lastUploadETag) {
            // Find the previous upload - etag in the x-csw-if-revision-exists is just the revision number
            $this->lastRevision = $this->dictHelper->getSyncHistoryByRevisionNumber($this->dictName, $lastUploadETag);
            if (!$this->lastRevision) {
                // Didn't find the previous upload - server and client sync history do not match
                // Return an error, client can follow up with a full sync (no x-csw-if-revision-exists)
                // Provide the last sync that server has record of (if there is one)
                // so that client can possibly synced based off that revision
                //Let the parser know to stop processing
                $lastSyncForDevice = $this->dictHelper->getLastSyncForDevice($this->dictName, $this->device);
                $this->logger->debug("Missing server revision for etag $lastUploadETag");
                if ($lastSyncForDevice) {
                    $this->setResponse(new CSProResponse(json_encode($lastSyncForDevice, JSON_THROW_ON_ERROR), CSProResponse::HTTP_PRECONDITION_FAILED));
                    $this->stopProcessing();
                    return;
                } else {
                    $this->setResponse(new CSProResponse(json_encode([], JSON_FORCE_OBJECT), CSProResponse::HTTP_PRECONDITION_FAILED));
                    $this->stopProcessing();
                    return;
                }
            }
        }
        // insert a row into the sync history with the new version
        //SynchHistoryEntry out of transaction to prevent dead locks
        $this->currentRevisionNumber = $this->dictHelper->addSyncHistoryEntry($this->device, $this->userName, $this->dictName, 'put', '');
        if ($this->isBinaryJSON && count($this->uploadedBinaryCaseItems) > 0) {
            $this->dictHelper->addBinarySyncHistoryEntry($this->dictName, $this->uploadedBinaryCaseItems, $this->currentRevisionNumber);
        }
    }

    public function finalizeUploadCases() {
        if ($this->stopProcessing == true)
            return;
        $this->pdo->commit();
        $this->logger->debug('Done Uploading Cases!!');
        $this->processUploadResponse();
    }

    public function processUploadResponse() {
        $result = [];
        if ($this->stopProcessing == true)
            return;
        $response = null;

        //if only first level JSON is available because of "ClientCases":[] element is missin for "GET"
        //initUploadcases has to be run to set the top level values
        if ($this->initialized == false) {
            $this->initUploadCases();
        }

// return the cases that were added or modified since the lastRevision
        $result ['code'] = 200;
        $result ['description'] = 'Success';
        $response = new CSProResponse(json_encode($result));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        $response->headers->set('ETag', $this->currentRevisionNumber);
        $response->headers->set('x-csw-current-revision', $this->currentRevisionNumber); //nginx  strips Etag, now using custom header
        $this->setResponse($response);
    }

    public function validateJSON() {
        $uri = '#/definitions/CaseList';
        if (!empty($this->jsonCasesBody)) {
            $jsonUploadRequest = json_decode($this->jsonCasesBody, false, 512, JSON_THROW_ON_ERROR);
        } else {
            $jsonUploadRequest = json_decode(json_encode($this->cases, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        }
//var_dump( $jsonUploadRequest);
        $csproJsonValidator = new CSProJsonValidator($this->logger);
        $csproJsonValidator->validateDecodedJSON($jsonUploadRequest, $uri);
    }

    function processCasesInsertOrUpdate() {
        if ($this->stopProcessing == true)
            return;
        $this->logger->debug('processCasesInsertOrUpdate!!! #cases:' . (is_countable($this->cases) ? count($this->cases) : 0));

        if ((is_countable($this->cases) ? count($this->cases) : 0) > 0) {
            $this->validateJSON();
            if ($this->isBinaryJSON) {
                $this->caseBinaryItems = $this->dictHelper->getBinaryCaseItems($this->cases);
            }
            //get binary item association before compressing cases json
            // for each row get the record list array to multi-line string for the questionnaire data
            $this->dictHelper->prepareJSONForInsertOrUpdate($this->dictName, $this->cases);
            // add the cases that do not exist using the new revision#
            // update the cases the cases that exist using the new revision#
            $insertQuery = [];
            $insertData = [];
            $n = 0;

            // echo 'Number of Cases: ' . count($params['clientCases']);
            $stm = 'INSERT INTO ' . $this->dictName . ' (`guid`,' . implode(',', array_map(fn($col) => "`$col`", $this->colNames)) . ') VALUES ';
            foreach ($this->cases as $row) {
                // (UNHEX(REPLACE(:guid,"-","")),:questionnaire,:revision,:deleted)
                $insertQuery [] = '(UNHEX(REPLACE(:guid' . $n . ',"-","")),' . implode(',', array_map(fn($col) => ":$col$n", $this->colNames)) . ')';
                $insertData ['guid' . $n] = $row ['id'];
                if (isset($row ['data']))
                    $insertData ['questionnaire' . $n] = $row ['data']; // CSPro <= 7.4
                else {
                    $insertData ['questionnaire' . $n] = $row ['level-1'];
                }

                $insertData ['caseids' . $n] = $row ['caseids'];
                $insertData ['label' . $n] = $row ['label'];
                $insertData ['revision' . $n] = $this->currentRevisionNumber;
                $insertData ['deleted' . $n] = $row ['deleted'] ? 1 : 0;
                $insertData ['verified' . $n] = $row ['verified'] ? 1 : 0;

                $insertData ['partial_save_mode' . $n] = null;
                $insertData ['partial_save_field_name' . $n] = null;
                $insertData ['partial_save_level_key' . $n] = null;
                $insertData ['partial_save_record_occurrence' . $n] = null;
                $insertData ['partial_save_item_occurrence' . $n] = null;
                $insertData ['partial_save_subitem_occurrence' . $n] = null;

                if (isset($row ['partialSave'])) {
                    $insertData ['partial_save_mode' . $n] = $row ['partialSave'] ['mode'];
                    $insertData ['partial_save_field_name' . $n] = $row ['partialSave'] ['field'] ['name'] ?? null;
                    $insertData ['partial_save_level_key' . $n] = $row ['partialSave'] ['field'] ['levelKey'] ?? "";
                    $insertData ['partial_save_record_occurrence' . $n] = $row ['partialSave'] ['field'] ['recordOccurrence'] ?? 0;
                    $insertData ['partial_save_item_occurrence' . $n] = $row ['partialSave'] ['field'] ['itemOccurrence'] ?? 0;
                    $insertData ['partial_save_subitem_occurrence' . $n] = $row ['partialSave'] ['field'] ['subitemOccurrence'] ?? 0;
                }

                $insertData ['clock' . $n] = $row ['clock'];
                $n++;
            }
            if (!empty($insertQuery)) {
                $stm .= implode(', ', $insertQuery);

                $stm .= ' ON DUPLICATE KEY UPDATE `guid`=VALUES(`guid`), ';
                $stm .= implode(',', array_map(fn($col) => "`$col`=VALUES(`$col`)", $this->colNames));
                $stm .= ';';

                // $app['pdo']->getProfiler()->setActive(true); //Uncomment this line to activate profiler
                // prepare insert statement and bind values
                try {
                    $stmt = $this->pdo->prepare($stm);
                    $result = $stmt->execute($insertData); // true if successful
                    // add notes to the notes table
                    $this->dictHelper->addCaseNotes($this->cases, $this->dictName . '_notes');
                    if ($this->isBinaryJSON) {
                        $this->dictHelper->associateCaseBinaryItems($this->dictName, $this->caseBinaryItems); //finally add the case binary items association
                    }
                }
                catch (\Exception $e) {
                    $this->logger->error('Failed inserting cases: ', ["context" => (string) $e]);
                    throw $e;
                }
                // dumpProfilerOutput($app) //Uncomment this line to dump the profiler results
            }
        }
    }

    public function startDocument(): void {
        $this->logger->debug('start reading cases to upload!!!');
        if (!$this->initialized)
            $this->initUploadCases();

        $this->stack = [];
        $this->level = 0;
// Key is an array so that we can can remember keys per level to avoid
// it being reset when processing child keys.
        $this->key = [];
    }

    public function endDocument(): void {
        if ($this->stopProcessing == true)
            return;
        $this->logger->debug('end reading cases to upload!!!');
//process the final chunk
        if ((is_countable($this->cases) ? count($this->cases) : 0) > 0) {
            $this->processCasesInsertOrUpdate();
            $this->cases = []; //clear the array after processing the chunk
        }
// call finalize
        $this->finalizeUploadCases();
    }

    public function startObject(): void {
        $this->level++;
        $this->stack[] = [];
    }

    public function endObject(): void {
        $this->level--;
        $obj = array_pop($this->stack);
        if (empty($this->stack)) {
// doc is DONE!
        } else {
            if ($this->level == 1) //store cases
                $this->cases[] = $obj;
            else
                $this->value($obj);
        }
        // store the cases for processing as chunks
        //$this->logger->debug ( 'Object processing !!! #cases:' .count($this->cases));
        if ($this->level == 1 && ((is_countable($this->cases) ? count($this->cases) : 0) >= self::CHUNK_SIZE)) {
            $this->processCasesInsertOrUpdate();
            $this->cases = []; //clear the array after processing the chunk
        }
    }

    public function startArray(): void {
        $this->startObject();
    }

    public function endArray(): void {
        $this->endObject();
    }

    /**
     * @param string $key
     */
    public function key($key): void {
        $this->key[$this->level] = $key;
    }

    /**
     * Value may be a string, integer, boolean, null
     * @param mixed $value
     */
    public function value($value) {
        $obj = array_pop($this->stack);
        if (!empty($this->key[$this->level])) {
            $obj[$this->key[$this->level]] = $value;
            $this->key[$this->level] = null;
        } else {
            $obj[] = $value;
        }
        $this->stack[] = $obj;
    }

    public function whitespace($whitespace): void {
// do nothing
    }

    /* public function dumpProfilerOutput($app) {
      if ($app ['debug'] == true) {
      $profiles = $pdo->getProfiler()->getProfiles();
      foreach ($profiles as $key => $val) {
      echo $profiles [$key] ['statement'];
      echo $profiles [$key] ['trace'];
      var_dump($profiles [$key] ['bind_values']);
      }
      }
      } */
}
