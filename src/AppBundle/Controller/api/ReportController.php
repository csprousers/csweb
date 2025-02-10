<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\Geocode\GeocodeParser;
use AppBundle\CSPro\Geocode\GeocodeValidator;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\Data\DataSettings;
use PDO;

function StandardizeIntFromHeader($value, $nullDefault) {
    if ($value === null)
    // A key is used to access a value in a header, but the value does not exist.
    // The returned value is null.
        $value = $nullDefault;
    else
    // A key is used to access a value in a header, but the value does exist.
    // The returned value is a string.
        $value = (int) $value;

    return $value;
}

class ReportController extends AbstractController implements ApiTokenAuthenticatedController {

    public const MAX_IMPORT_AREA_NAMES_PER_ITERATION = 500;

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    #[Route('/sync-report', methods: ['GET'])]
            function getSyncReportList(Request $request): CSProResponse {
        $dictionaryIds = [];
        $columnFilters = [];
        $rowCount = 0;
        $rowsFiltered = 0;

        $start = $request->headers->get('x-csw-report-start');
        if ($start == null || $start == "") {
            $start = 0;
        }

        $length = $request->headers->get('x-csw-report-length');
        if ($length == null || $length == "") {
            $length = 1000;
        }

        $search = $request->headers->get('x-csw-report-search');
        if ($search === null) {
            $search = "";
        }

        $orderColumn = $request->headers->get('x-csw-report-order-column');
        if ($orderColumn == null || $orderColumn == "") {
            $orderColumn = 1;
        } else {
            $orderColumn++; // SQL doesn't use 0 column as the first column
        }

        $orderDirection = $request->headers->get('x-csw-report-order-direction');
        if ($orderDirection == null || $orderDirection == "") {
            $orderDirection = "ASC";
        }

        $areaNamesColumnCount = StandardizeIntFromHeader($request->headers->get('x-csw-report-area-names-column-count'), 0);

        $dictionary = $request->headers->get('x-csw-report-dictionary');

        $dictionaryIdCount = StandardizeIntFromHeader($request->headers->get('x-csw-report-dictionary-id-count'), 0);

        $validDictionaryIds = TRUE;
        if ($dictionaryIdCount === 0)
            $validDictionaryIds = FALSE;

        for ($i = 1; $i <= $dictionaryIdCount; $i++) {
            $dictionaryIds[$i] = $request->headers->get('x-csw-report-dictionary-id-' . $i);

            // All dictionary ids must contain a name
            if ($dictionaryIds[$i] === null || $dictionaryIds[$i] === "") {
                $validDictionaryIds = FALSE;
                $this->logger->debug('The dictionary id does not contain a name.');
            }
        }

        if (!$validDictionaryIds) {
            // Either there are no dictionary ids or the dictionary id(s) do not contain a name, so skip query and return as if nothing was found
            $response = new CSProResponse(json_encode([]), CSProResponse::HTTP_OK);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $response->headers->set('x-csw-report-count', [0]);
            $response->headers->set('x-csw-report-filtered', [0]);

            return $response;
        }

        // Each dictionary id corresponds to a column in the data table. Plus one more for the aggregate column.
        // There is one column filter for each column.
        $columnFilterCount = $dictionaryIdCount + 1;
        $searchColumnCount = $dictionaryIdCount + 1;
        for ($i = 0; $i < $columnFilterCount; $i++) {
            $columnFilters[$i] = $request->headers->get('x-csw-report-column-filter-' . $i);
        }

        $dictionaryIdsStm = "";
        for ($i = 1; $i <= $dictionaryIdCount; $i++) {
            if ($dictionaryIdsStm === "") {
                // First column
                $dictionaryIdsStm = "`$dictionaryIds[$i]`";
            } else {
                // Each additional column
                $dictionaryIdsStm .= ", `$dictionaryIds[$i]`";
            }
        }

        // Sync data to display in table
        try {
            //get the target schema name 
            $stm = "SELECT host_name, schema_name, schema_user_name, AES_DECRYPT(schema_password, '" . "cspro') as `password` FROM `cspro_dictionaries_schema` JOIN `cspro_dictionaries` ON dictionary_id = cspro_dictionaries.id WHERE cspro_dictionaries.dictionary_name = :dictName";
            $bind = ['dictName' => $dictionary];

            $result = $this->pdo->fetchOne($stm, $bind);
            $targetDBName = $result['schema_name'];
            $this->logger->debug("Target schema name is : $targetDBName for dictionary: $dictionary");

            // Don't count the id and label column as a level of geography
            $areaNamesLevelCount = $areaNamesColumnCount - 2;
            $subStm = "SELECT";
            $labelColumnCount = 0;
            $numericColumnCount = 0;
            $complementaryColumns = min($dictionaryIdCount, $areaNamesLevelCount);
            $maxColumns = max($dictionaryIdCount, $areaNamesLevelCount);
            for ($i = 1; $i <= $maxColumns; $i++) {
                if ($i <= $complementaryColumns) {
                    // Dictionary id item has corresponding area names column
                    // Dictionary table and area names table will be joined and a label is returned
                    $subStm .= " COALESCE(`area_names$i`.`label`, `$dictionaryIds[$i]`) AS label$i,";
                    $labelColumnCount++;
                } elseif ($i <= $dictionaryIdCount) {
                    // Dictionary id item does not have a corresponding area names column
                    // Only dictionary table will be used, so a code is returned
                    $subStm .= " `$dictionaryIds[$i]`,";
                    $numericColumnCount++;
                }
                //else ($i <= $areaNamesLevelCount)
                // Do nothing
            }

            //!AI repeating columns selection, but only for ID items, no area labels
            //this is needed to later get case ID for viewing cases
            for ($i = 1; $i <= $maxColumns; $i++) {
                if ($i <= $dictionaryIdCount) {
                    // Dictionary id item does not have a corresponding area names column
                    // Only dictionary table will be used, so a code is returned
                    $subStm .= " `$dictionaryIds[$i]` AS $dictionaryIds[$i]_v,";
                }
                //else ($i <= $areaNamesLevelCount)
                // Do nothing
            }


            $subStm .= " COUNT(*) AS `total_count` FROM";

            $numericColumnFilterStm = "";
            $labelColumnFilterStm = "";
            for ($i = 0; $i < $columnFilterCount - 1; $i++) {
                // Add column filters's like clause for each column except for aggregate column
                $oneBasedIndex = $i + 1;
                if ($columnFilters[$i] !== "") {
                    if (is_numeric($columnFilters[$i])) {
                        // Add numeric like clause
                        if ($numericColumnFilterStm !== "") {
                            $numericColumnFilterStm .= " AND ";
                        }

                        $numericColumnFilterStm .= "CAST(`$dictionaryIds[$oneBasedIndex]` as CHAR) LIKE :columnFilter$i";
                    } else {
                        // Add label like clause
                        if ($labelColumnFilterStm !== "") {
                            $labelColumnFilterStm .= " AND ";
                        }

                        $labelColumnFilterStm .= "`area_names$oneBasedIndex`.`label` LIKE :columnFilter$i";
                    }
                }
            }

            $numericSearchStm = "";
            $labelSearchStm = "";
            $hasLabelColumn = 0 < $labelColumnCount ? true : false;
            $hasNumericColumn = 0 < $numericColumnCount ? true : false;
            if ($search !== "") {
                if (!is_numeric($search) || (!$hasNumericColumn && is_numeric($search))) {
                    // Create like clause for:
                    //    1. An alpha and label column. Quintessential case.
                    //    2. A numeric and label column when no numeric column exists. This will always return no
                    //       matches.
                    for ($i = 0; $i < $labelColumnCount; $i++) {
                        $oneBasedIndex = $i + 1;
                        // Add label like clause
                        if ($labelSearchStm !== "") {
                            $labelSearchStm .= " OR ";
                        }

                        $labelSearchStm .= "`area_names$oneBasedIndex`.`label` LIKE :search";
                    }
                }

                if (is_numeric($search) || (!$labelColumnCount && !is_numeric($search))) {
                    // Create like clause for:
                    //    1. A numeric and numeric column. Quintessential case.
                    //    2. An alpha and numeric column when no alpha column exists. This will always return no
                    //       matches.
                    // If there are a mix of columns with labels and numerics, then the numerics will come after the
                    // labels. An example is if we had a dictionary with four levels (country, region, division, and
                    // state), but upload labels for only (country, region, and division). In this example the country,
                    // region, and division would be labels and the state would be a numeric.
                    $numericStart = $labelColumnCount;
                    $numericEnd = $labelColumnCount + $numericColumnCount;
                    for ($i = $numericStart; $i < $numericEnd; $i++) {
                        $oneBasedIndex = $i + 1;
                        // Add label like clause
                        if ($numericSearchStm !== "") {
                            $numericSearchStm .= " OR ";
                        }

                        $numericSearchStm .= "CAST(`$dictionaryIds[$oneBasedIndex]` as CHAR) LIKE :search";
                    }
                }
            }

            $numericLikeStm = "";
            $isNumericColumnFilter = $numericColumnFilterStm === "" ? false : true;
            $isNumericSearch = $numericSearchStm === "" ? false : true;
            if ($isNumericColumnFilter && $isNumericSearch) {
                $numericLikeStm = "($numericColumnFilterStm) AND ($numericSearchStm)";
            } else if ($isNumericColumnFilter) {
                $numericLikeStm = $numericColumnFilterStm;
            } else if ($isNumericSearch) {
                $numericLikeStm = $numericSearchStm;
            }

            $numericWhereStm = "";
            if ($numericLikeStm !== "") {
                $numericWhereStm = " (SELECT * FROM `$targetDBName`.`level-1` WHERE $numericLikeStm) AS dictionary_data";
            }

            if ($numericWhereStm === "") {
                $subStm .= " ( SELECT  `L1`.* FROM `$targetDBName`.`level-1` AS L1 LEFT JOIN `$targetDBName`.`cases` AS C1 ON `C1`.`id` =  `L1`.`case-id` WHERE `C1`.`deleted` = 0 ) AS T1";
            } else {
                $subStm .= $numericWhereStm;
            }

            for ($i = 1; $i <= $complementaryColumns; $i++) {
                $subStm .= " LEFT JOIN `cspro_area_names` AS `area_names$i` ON ";
                for ($j = 1; $j <= $areaNamesLevelCount; $j++) {
                    $subStm .= "`area_names$i`.`level$j` = ";

                    if ($i < $j) {
                        //$this->logger->debug("X when $i < $j");
                        $subStm .= "'X'";
                    } else {
                        //$this->logger->debug("DictionaryId when $i >= $j");
                        $subStm .= "`$dictionaryIds[$j]`";
                    }

                    if ($j != $areaNamesLevelCount) {
                        $subStm .= " AND ";
                    }
                }
            }

            $labelLikeStm = "";
            $isLabelColumnFilter = $labelColumnFilterStm === "" ? false : true;
            $isLabelSearch = $labelSearchStm === "" ? false : true;
            if ($isLabelColumnFilter && $isLabelSearch) {
                $labelLikeStm = "($labelColumnFilterStm) AND ($labelSearchStm)";
            } else if ($isLabelColumnFilter) {
                $labelLikeStm = $labelColumnFilterStm;
            } else if ($isLabelSearch) {
                $labelLikeStm = $labelSearchStm;
            }

            $labelWhereStm = "";
            if ($labelLikeStm !== "") {
                $labelWhereStm = " WHERE $labelLikeStm";
            }

            $subStm .= $labelWhereStm;

            $groupByStm = " GROUP BY $dictionaryIdsStm";
            $subStm .= $groupByStm;

            $aggregateColumnFilterIndex = $columnFilterCount - 1;
            if ($columnFilters[$aggregateColumnFilterIndex] !== "") {
                $subStm .= " HAVING `total_count` = :columnFilter$aggregateColumnFilterIndex";
            }

            $selectStm = $subStm;

            if (strtolower($orderDirection) == 'asc') {
                $orderByStm = ' ORDER BY :column ASC LIMIT :length OFFSET :start ';
                $selectStm .= $orderByStm;
            } else {
                $orderByStm = ' ORDER BY :column DESC LIMIT :length OFFSET :start';
                $selectStm .= $orderByStm;
            }

            //$this->logger->debug("selectStm = $selectStm");
            // Expected format for id: COUNTRY_CODE, labels: no, column filter: no, search: no
            // SELECT `id_COUNTRY_CODE`, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT`
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: no, column filter: yes, search: no
            // SELECT `id_COUNTRY_CODE`, COUNT(*) AS `total_count`
            // FROM (SELECT * FROM `HOUSEHOLD_4_LEVEL_DICT` WHERE CAST(`id_COUNTRY_CODE` as CHAR) LIKE :columnFilter0) AS dictionary_data
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: no, column filter: yes (aggregate), search: no
            // SELECT `id_COUNTRY_CODE`, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT`
            // GROUP BY `id_COUNTRY_CODE`
            // HAVING `total_count` = :columnFilter1 ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: no, column filter: no, search: yes
            // SELECT `id_COUNTRY_CODE`, COUNT(*) AS `total_count`
            // FROM (SELECT * FROM `HOUSEHOLD_4_LEVEL_DICT` WHERE CAST(`id_COUNTRY_CODE` as CHAR) LIKE :search) AS dictionary_data
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: REGION_CODE, labels: no, column filter: yes, search: yes
            // SELECT `id_COUNTRY_CODE`, `id_REGION_CODE`, COUNT(*) AS `total_count`
            // FROM (SELECT * FROM `HOUSEHOLD_4_LEVEL_DICT` WHERE (CAST(`id_COUNTRY_CODE` as CHAR) LIKE :columnFilter0) AND (CAST(`id_COUNTRY_CODE` as CHAR) LIKE :search OR CAST(`id_REGION_CODE` as CHAR) LIKE :search)) AS dictionary_data
            // GROUP BY `id_COUNTRY_CODE`, `id_REGION_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: yes, column filter: no, search: no
            // SELECT `area_names1`.`label` AS label1, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT` LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: yes, column filter: yes, search: no
            // SELECT `area_names1`.`label` AS label1, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT` LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // WHERE `area_names1`.`label` LIKE :columnFilter0
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start 
            // Expected format for id: COUNTRY_CODE, labels: yes, column filter: yes (aggregate), search: no
            // SELECT `area_names1`.`label` AS label1, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT` LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // GROUP BY `id_COUNTRY_CODE`
            // HAVING `total_count` = :columnFilter1 ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: COUNTRY_CODE, labels: yes, column filter: no, search: yes
            // SELECT `area_names1`.`label` AS label1, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT` LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // WHERE `area_names1`.`label` LIKE :search
            // GROUP BY `id_COUNTRY_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: REGION_CODE, labels: yes, column filter: yes, search: yes
            // SELECT `area_names1`.`label` AS label1, `area_names2`.`label` AS label2, COUNT(*) AS `total_count`
            // FROM `HOUSEHOLD_4_LEVEL_DICT`
            // LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // LEFT JOIN `cspro_area_names` AS `area_names2` ON `area_names2`.`level1` = `id_COUNTRY_CODE` AND `area_names2`.`level2` = `id_REGION_CODE` AND `area_names2`.`level3` = 'X'
            // WHERE (`area_names1`.`label` LIKE :columnFilter0) AND (`area_names1`.`label` LIKE :search OR `area_names2`.`label` LIKE :search)
            // GROUP BY `id_COUNTRY_CODE`, `id_REGION_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start
            // Expected format for id: STATE_CODE, label: both, column filter: yes, search: no
            // SELECT `area_names1`.`label` AS label1, `area_names2`.`label` AS label2, `area_names3`.`label` AS label3, `id_STATE_CODE`, COUNT(*) AS `total_count`
            // FROM (SELECT * FROM `HOUSEHOLD_4_LEVEL_DICT` WHERE CAST(`id_STATE_CODE` as CHAR) LIKE :columnFilter3) AS dictionary_data
            // LEFT JOIN `cspro_area_names` AS `area_names1` ON `area_names1`.`level1` = `id_COUNTRY_CODE` AND `area_names1`.`level2` = 'X' AND `area_names1`.`level3` = 'X'
            // LEFT JOIN `cspro_area_names` AS `area_names2` ON `area_names2`.`level1` = `id_COUNTRY_CODE` AND `area_names2`.`level2` = `id_REGION_CODE` AND `area_names2`.`level3` = 'X'
            // LEFT JOIN `cspro_area_names` AS `area_names3` ON `area_names3`.`level1` = `id_COUNTRY_CODE` AND `area_names3`.`level2` = `id_REGION_CODE` AND `area_names3`.`level3` = `id_DIVISION_CODE`
            // WHERE `area_names1`.`label` LIKE :columnFilter0
            // GROUP BY `id_COUNTRY_CODE`, `id_REGION_CODE`, `id_DIVISION_CODE`, `id_STATE_CODE` ORDER BY :column ASC LIMIT :length OFFSET :start

            $query = $this->pdo->prepare($selectStm);

            for ($i = 0; $i < $columnFilterCount; $i++) {
                if ($columnFilters[$i] !== '') {
                    $query->bindValue(":columnFilter$i", $columnFilters[$i], PDO::PARAM_STR);
                }
            }
            if ($search !== "") {
                $query->bindValue(':search', "%$search%", PDO::PARAM_STR);
            }
            $query->bindValue(':column', (int) $orderColumn, PDO::PARAM_INT);
            $query->bindValue(':length', (int) $length, PDO::PARAM_INT);
            $query->bindValue(':start', (int) $start, PDO::PARAM_INT);
            $query->execute();
            $result = $query->fetchAll();

            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed getting list for sync report';
            $this->logger->error($result['description'], ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'report_get_error', $result['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        // Total row (entries) count
        try {
            $totalRowCountStm = "SELECT COUNT(*) FROM " .
                    "(SELECT COUNT(*) FROM `$targetDBName`.`level-1` GROUP BY $dictionaryIdsStm) AS distinct_geography";

            $query = $this->pdo->prepare($totalRowCountStm);
            $query->execute();
            $rowCount = $query->fetch();
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed getting total row count for sync report';
            $this->logger->error($result['description'], ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'report_get_error', $result['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        // Filter row (entries) count
        try {
            $filterRowCountStm = "SELECT COUNT(*) FROM " .
                    "($subStm) AS distinct_geography";

            $query = $this->pdo->prepare($filterRowCountStm);

            for ($i = 0; $i < $columnFilterCount; $i++) {
                if ($columnFilters[$i] !== '') {
                    $query->bindValue(":columnFilter$i", $columnFilters[$i], PDO::PARAM_STR);
                }
            }
            if ($search !== "") {
                $query->bindValue(':search', "%$search%", PDO::PARAM_STR);
            }

            $query->execute();
            $rowsFiltered = $query->fetch();
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed getting filter row count for sync report';
            $this->logger->error($result['description'], ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'report_get_error', $result['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        $response->headers->set('x-csw-report-count', $rowCount);
        $response->headers->set('x-csw-report-filtered', $rowsFiltered);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/delete-area-names', methods: ['DELETE'])]
            function deleteAreaNames(Request $request): CSProResponse {
        $result = [];
        $dropTable = "DROP TABLE IF EXISTS `cspro_area_names`";

        try {
            $query = $this->pdo->prepare($dropTable);
            $query->execute();
            $result['description'] = 'The area names have been deleted. Labels will not be shown in table.';
            $this->logger->debug($result['description']);
            $response = new CSProResponse(json_encode($result['description']), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed to drop `cspro_area_names` table';
            $this->logger->error($result['description'], ['context' => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'role_drop_area_names_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/report-area-names-column-count', methods: ['GET'])]
            function getReportAreaNamesColumnCountList(Request $request): CSProResponse {
        $result = [];
        $selectStm = "select database()";

        try {
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $database = $query->fetchColumn();
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed getting area names column count';
            $this->logger->error('Failed to select current database', ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'report_get_area_names_column_count_error', $result['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        $selectStm = "SELECT COUNT(*) FROM `information_schema`.`columns` WHERE `table_schema` = '$database' AND `table_name` LIKE '%cspro_area_names%'";

        try {
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $result = $query->fetchColumn();
            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $result['code'] = 500;
            $result['description'] = 'Failed getting area names column count';
            $this->logger->error($result['description'], ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError($result['code'], 'report_get_area_names_column_count_error', $result['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/report-dictionaries', methods: ['GET'])]
            function getReportDictionariesList(Request $request): CSProResponse {
        // only include dictionaries with at least one processed case
        $result = [];

        try {
            $dataSettings = new DataSettings($this->pdo, $this->logger);
            $dataSettings = $dataSettings->getDataSettings();
            foreach ($dataSettings as $dataSetting) {
                if (0 < $dataSetting['processedCases']) {
                    // at least one processed case exists
                    $dictionary = array('dictionary_name' => $dataSetting['name'], 'dictionary_label' => $dataSetting['label']);
                    array_push($result, $dictionary);
                }
            }
            usort($result, function ($a, $b) {return strnatcmp($a['label'] , $b['label']);});
            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting list of dictionaries for sync report', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting list of dictionaries for sync report';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'sync_report_get_dictionaries_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/report-dictionary-ids', methods: ['GET'])]
            function getReportDictionaryIdsList(Request $request): CSProResponse {
        $dictionaryName = $request->headers->get('x-csw-report-dictionary');
//        $showStm = "SHOW COLUMNS FROM $dictionary LIKE 'id_%'";

        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
            $level = $dictionary->getLevels()[0];
            $result = [];

            for ($iItem = 0; $iItem < (is_countable($level->getIdItems()) ? count($level->getIdItems()) : 0); $iItem++) {
                $result[] = strtolower($level->getIdItems()[$iItem]->getName());
            }
            $this->logger->debug('id items are ' . print_r($result, true));
            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting report dictionary ids list', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting report dictionaries ids list';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'report_get_dictionary_ids_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/import-area-names', methods: ['POST'])]
            function importAreaNames(Request $request): CSProResponse {
        $response = new CSProResponse();
        $cType = $request->headers->get('Content-Type');
        $this->logger->debug('Content Type: ' . $cType);
        if ($cType == 'text/plain') {
            $this->logger->debug('********TXT contentType:' . $cType . '*******');
            $response = $this->convertGeocodesToAreaNames($request);
        } else {
            $errMsg = 'Failed importing area names. Content-Type:' . $cType . ' must be txt';
            $this->logger->error($errMsg);
            $response->setError(500, 'role_content_type_error', $errMsg);
        }

        return $response;
    }

    //TODO: refactor &&&
    function convertGeocodesToAreaNames(Request $request) {
        $result = [];
        // INI changes are for the duration of the script and are restored once the script ends
        $this->logger->debug("Temporarily set INI properties for import of area names file");
        $maxScriptExecutionTime = $this->getParameter('csweb_api_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);
        // Turn off output buffering
        ini_set('output_buffering', 'off');
        // Turn off PHP output compression
        ini_set('zlib.output_compression', false);
        // Implicitly flush the buffer(s)
        ini_set('implicit_flush', true);
        ob_implicit_flush(true);
        // Clear, and turn off output buffering
        while (ob_get_level() > 0) {
            // Get the curent level
            $level = ob_get_level();
            // End the buffering
            ob_end_clean();
            // If the current level has not changed, abort
            if (ob_get_level() == $level)
                break;
        }

        // Create a streamed response
        $response = new StreamedResponse();
        $content = $request->getContent();

        $headerRow = $request->headers->get('x-csw-data-header');
        isset($headerRow) && $headerRow === "1" ? $headerRow = true : $headerRow = false;
        $parser = new GeocodeParser();
        $validator = new GeocodeValidator();

        try {
            // Validate geocodes import
            $isValid = $validator->validateImportGeocodes($content, $headerRow, $this->logger);

            if ($isValid) {
                $maxGeocodesImport = $this->getParameter('csweb_api_max_import');
                $linesProcessedCount = 0;
                $areaNames = $parser->parseGeocodes($content, $headerRow, $this->logger, $linesProcessedCount, $maxGeocodesImport);
            } else {
                $strMsg = "";
                foreach ($validator->getErrors() as $error) {
                    $strMsg .= sprintf("%s<br/>", $error);
                }
                $this->logger->debug($strMsg);
                $response = new CSProResponse();
                $response->setError(400, 'geocodes_import_file_invalid', $strMsg);
                return $response;
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception');
            ${$this}->logger->error($e->getMessage());
            $response = new CSProResponse();
            $response->setError(400, 'geocodes_import_file_invalid', $e->getMessage());
            return $response;
        }

        $dropTable = "DROP TABLE IF EXISTS `cspro_area_names`";
        try {
            $query = $this->pdo->prepare($dropTable);
            $query->execute();
            $this->logger->debug('If `cspro_area_names` table exists then drop it');
        } catch (\Exception $e) {
            $this->logger->error('Failed to drop `cspro_area_names` table', ['context' => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed to drop `cspro_area_names` table';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'role_drop_area_names_error', $result ['description']);
            return $response;
        }

        // Create table
        // Determine attribute count of geocode data
        $attrCount = is_countable($areaNames[0]) ? count($areaNames[0]) : 0;

        $columnNames = [];
        for ($i = 0; $i < $attrCount; $i++) {
            if ($i === $attrCount - 1) {
                // Last attr
                $columnNames[$i] = 'label';
            } else {
                $columnNum = $i + 1;
                $columnNames[$i] = 'level' . $columnNum;
            }
        }

        $createStm = "CREATE TABLE IF NOT EXISTS `cspro_area_names` (`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY";
        for ($i = 0; $i < $attrCount; $i++) {
            $createStm .= ", `$columnNames[$i]` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL";
        }

        $createStm .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            $query = $this->pdo->prepare($createStm);
            $query->execute();
            $this->logger->debug('Create `cspro_area_names` table');
        } catch (\Exception $e) {
            $this->logger->error('Failed to create `cspro_area_names` table', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed to create `cspro_area_names` table';
            $response = new CSProResponse();
            $response->setError($result['code'], 'role_create_area_names_error', $result['description']);
            return $response;
        }

        $this->logger->debug("Processing area names...");
        // $maxAreaNamesImportedPerIteration - specifies the max area names for each insert to sql table. For very large imports, increase this to process more user inserts for each iteration
        // you may also have to increase the php memory limit and mysql memory limit for the packet by increasing max_allowed_packet say by using MySQL SET GLOBAL max_allowed_packet=512M
        $maxAreaNamesImportedPerIteration = ReportController::MAX_IMPORT_AREA_NAMES_PER_ITERATION;
        $logger = $this->logger;
        $response->setCallback(function () use ($logger, $maxAreaNamesImportedPerIteration, $areaNames, $linesProcessedCount) {
            $percentComplete = null;
            $responseDescription = "Success";
            // Init the block size to 1% of the total number. If it is exceeds the $maxAreaNamesImportedPerIteration size then use the $maxAreaNamesImportedPerIteration
            // to give users control for very large imports without having to increase the mysql max_allowed_packet limit
            $blockSize = isset($areaNames) && (is_countable($areaNames) ? count($areaNames) : 0) ? max((is_countable($areaNames) ? count($areaNames) : 0) * 0.01, 50) : 50;
            $blockSize = $blockSize > $maxAreaNamesImportedPerIteration ? $maxAreaNamesImportedPerIteration : $blockSize;

            $areaNamesToInsert = [];
            for ($i = 0; $i < (is_countable($areaNames) ? count($areaNames) : 0); $i++) {
                array_push($areaNamesToInsert, $areaNames[$i]);

                if ($i == ((is_countable($areaNames) ? count($areaNames) : 0) - 1)) {
                    // Final block
                    $this->insertAreaNames($areaNamesToInsert);
                    $percentComplete = 100;
                } else if ($blockSize == count($areaNamesToInsert)) {
                    // Interim block
                    $percentComplete = round($i / (is_countable($areaNames) ? count($areaNames) : 0) * 100);

                    $this->insertAreaNames($areaNamesToInsert);
                    unset($areaNamesToInsert); // clear $areaNames array
                    $areaNamesToInsert = []; // clear $areaNames array

                    $responseCode = $percentComplete === 100 ? 200 : 206;
                    $strJSONResponse = json_encode(["code" => $responseCode, "description" => $responseDescription, 'progress' => $percentComplete, 'count' => $linesProcessedCount, 'status' => "Success"], JSON_THROW_ON_ERROR);
                    echo '\n' . $strJSONResponse;
                    $this->logger->debug($strJSONResponse);

                    flush();
                    $strJSONResponse = ''; //reset json string response
                }
            }
            $responseCode = $percentComplete === 100 ? 200 : 206;
            $strJSONResponse = json_encode(["code" => $responseCode, "description" => $responseDescription, 'progress' => $percentComplete, 'count' => $linesProcessedCount, 'status' => "Success"], JSON_THROW_ON_ERROR);
            echo '\n' . $strJSONResponse;
            $logger->debug($strJSONResponse);

            flush();
            $strJSONResponse = ''; //reset json string response
        });

        $response->headers->set('Content-Type', 'application/json');
        return $response->send();
    }

    function insertAreaNames($areaNames) {
        // Determine attribute count with first line of area names data
        $attrCount = is_countable($areaNames[0]) ? count($areaNames[0]) : 0;
        $columnStr = "";
        for ($i = 0; $i < $attrCount; $i++) {
            if ($i === $attrCount - 1) {
                // Last attr
                $columnStr .= "label";
            } else {
                $columnNum = $i + 1;
                $columnStr .= "level" . $columnNum . ", ";
            }
        }

        $stm = "INSERT INTO `cspro_area_names` (" . $columnStr . ") VALUES ";

        $values = "";
        $areaNamesCount = is_countable($areaNames) ? count($areaNames) : 0;
        $indexSerialized = 0;
        $areaNamesSerialized = [];
        for ($i = 0; $i < $areaNamesCount; $i++) {
            $areaName = $areaNames[$i];
            $values .= ("(");
            for ($j = 0; $j < $attrCount; $j++) {
                $values .= ":areaName$indexSerialized";
                $areaNamesSerialized[] = $areaName[$j];

                if ($j < $attrCount - 1) {
                    $values .= ", ";
                }

                ++$indexSerialized;
            }
            $values .= (")");

            if ($i + 1 != $areaNamesCount)
                $values .= ", ";
        }

        $stm .= $values;

        try {
            $pdoStm = $this->pdo->prepare($stm);

            $areaNamesSerializedCount = count($areaNamesSerialized);
            for ($i = 0; $i < $areaNamesSerializedCount; $i++) {
                $pdoStm->bindValue(":areaName$i", $areaNamesSerialized[$i], PDO::PARAM_STR);
            }

            $result = $pdoStm->execute();
        } catch (\Exception $e) {
            $this->logger->error('Failed inserting area names into cspro_area_names table', ['context' => (string) $e]);
            throw new \Exception('Failed inserting area names into cspro_area_names table', 0, $e);
        }
    }

    /**
     * Replaces any parameter placeholders in a query with the value of that
     * parameter. Useful for debugging. Assumes anonymous parameters from 
     * $params are are in the same order as specified in $query
     *
     * @param string $query The sql query with parameter placeholders
     * @param array $params The array of substitution parameters
     * @return string The interpolated query
     */
    public function interpolateQuery($query, $params) {
        $keys = [];
        $values = $params;

        # build a regular expression for each parameter
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $keys[] = '/:' . $key . '/';
            } else {
                $keys[] = '/[?]/';
            }

            if (is_array($value))
                $values[$key] = implode(',', $value);

            if (is_null($value))
                $values[$key] = 'NULL';
        }
        // Walk the array to see if we can add single-quotes to strings
        array_walk($values, function (&$v, $k) {
            if (!is_numeric($v) && $v != "NULL") {
                $v = "'" . $v . "'";
            }
        });

        $query = preg_replace($keys, $values, $query, 1, $count);

        return $query;
    }

}
