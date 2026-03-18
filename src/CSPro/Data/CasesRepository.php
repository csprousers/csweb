<?php

namespace App\CSPro\Data;

use App\Service\PdoHelper;
use Psr\Log\LoggerInterface;

/**
 * Description of CasesRepository
 *
 * @author savy
 */

enum CaseSearchFilter: string {
    case Uuid = 'uuid';
    case Key = 'key';
    case Position = 'position';
}

enum CaseSearchFilterOperator: string {
    case Equals = '=';
    case LessThan = '<';
    case LessThanEquals = '<=';
    case GreaterThanEquals = '>=';
    case GreaterThan = '>';
    case StartsWith = 'startswith';
}

enum CaseStatus: string {
    case All = 'all';
    case NotDeletedOnly = 'notDeletedOnly';
    case PartialsOnly = 'partialsOnly';
    case DuplicatesOnly = 'duplicatesOnly';
}

enum CaseOrder: int {
    case Ascending = 1;
    case Descending = 2;
}

enum CaseContent: string {
    case Count = 'count';
    case Identifiers = 'identifiers';
    case Summaries = 'summaries';
    case Cases = 'cases';
}

//currently supports retrieval by id and key as per the C++ client requirements
class CasesRepository {
    private const MAX_CASES_PER_QUERY = 1000;

    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger, private $dictName) {

    }

    //get cases by key or uuid
    public function getCasesContent($caseOptions, $casesCache): string {

        /*
          caseOptions
          {
            "content": "count | identifiers | summaries | cases",
            "requestMetadata": false,
            "status": "all | notDeletedOnly | partialsOnly | duplicatesOnly",
            "offset": 0,
            "limit": 500,
            "sort": {
              "order": "key" | "position",
              "ascending": true
            },
            "filter": {
              "operator": "=", "<" | "<=" | ">=" | ">" | "startswith",
              "type": "key | position | uuid",
              "value": "case_key | 5 <-- startswith will only apply to type=key"
            }
          }

          casesCache
          {
            "exclude": {
              "serverRevision": 8,
              "positions": [ 1, 2, 3 ]
            }
          }
        */

        $requestMetadata = !empty($caseOptions['requestMetadata']);
        $caseContent = $caseOptions['content'] ?? CaseContent::Cases->value;

        $bind = [];
        $stm = $this->getCaseSQL($requestMetadata, $caseContent, $casesCache, $bind);
        $stm .= $this->getCaseFilterSQL($caseOptions);

        $keyValue = $caseOptions['filter']['value'] ?? null;
        $bind['key_value'] = $keyValue;
        $bind['starts_with_key_value'] = "$keyValue%";

        $result = $this->pdo->fetchAll($stm, $bind);

        $jsonResult = '{';

        // give the query limit if it was potentially reached
        if (count($result) == self::MAX_CASES_PER_QUERY) {
            $jsonResult .= '"resultLimit":' . self::MAX_CASES_PER_QUERY . ',';
        }

        $jsonResult .= "\"$caseContent\":";

        if ($caseContent === CaseContent::Count->value) {
            $jsonResult .= $result[0]['count'];
        }
        else if ($caseContent === CaseContent::Identifiers->value) {
            $jsonResult .= json_encode($result, JSON_THROW_ON_ERROR);
        }
        else if ($caseContent === CaseContent::Summaries->value) {
            $this->clearSummaryDefaults($result);
            $jsonResult .= json_encode($result, JSON_THROW_ON_ERROR);
        }
        else if ($caseContent === CaseContent::Cases->value) {
            $this->processCaseBlobs($result);
            $jsonResult .= '[' .implode(',', array_column($result, 'case')) . ']';

            if ($requestMetadata) {
                foreach ($result as &$row) {
                    unset($row['case']);
                }

                $jsonResult .= ',"metadata":' . json_encode($result, JSON_THROW_ON_ERROR);
            }
        }
        else {
            $jsonResult .= json_encode("Invalid 'content' value: '$caseContent'", JSON_THROW_ON_ERROR);
        }

        $jsonResult .= '}';

        return $jsonResult;
    }

    public function getCaseSQL($requestMetadata, $caseContent, $casesCache, &$bind) {
        if ($caseContent === CaseContent::Count->value) {
            return "SELECT COUNT(*) AS `count` FROM `$this->dictName`";
        }

        $stm = "SELECT `$this->dictName`.`id` AS `position`";

        if ($requestMetadata) {
            $stm .= ', `revision`';
        }

        if ($caseContent === CaseContent::Cases->value) {
            if (
                isset($casesCache['exclude']['serverRevision']) &&
                is_array($casesCache['exclude']['positions']) &&
                !empty($casesCache['exclude']['positions'])
            ) {
                $bind['revision'] = $casesCache['exclude']['serverRevision'];

                $positionPlaceholders = [];

                foreach ($casesCache['exclude']['positions'] as $index => $position) {
                    $key = "pos$index";
                    $positionPlaceholders[] = ':' . $key;
                    $bind[$key] = $position;
                }

                $stm .= ', CASE WHEN `revision` <= :revision AND `id` IN (' . implode(',', $positionPlaceholders) . ') THEN NULL ELSE `questionnaire` END AS `case`';
            }
            else {
                $stm .= ', `questionnaire` AS `case`';
            }
        }
        else {
            $stm .= ', `key`, LCASE(CONCAT_WS("-", LEFT(HEX(`uuid`), 8), MID(HEX(`uuid`), 9, 4), MID(HEX(`uuid`), 13, 4), MID(HEX(`uuid`), 17, 4), RIGHT(HEX(`uuid`), 12))) AS `uuid`';

            if ($caseContent === CaseContent::Summaries->value) {
                $stm .= ', `label`, `deleted`, `verified`, `partial_save_mode` AS `partialSaveMode`, `note` AS `caseNote`';
            }
        }

        return $stm . " FROM `$this->dictName`";
    }

    public function getCaseFilterSQL($caseOptions): string {
        $stm = "";
        $strWhere = "";
        $strOrderBy = "";

        $filterType = $caseOptions['filter']['type'] ?? null;
        $filterOperator = $caseOptions['filter']['operator'] ?? null;
        $filterValue = $caseOptions['filter']['value'] ?? null;
        $enumValues = [];//['=', '<', '<=', '>=', '>', 'startswith'];
        foreach (CaseSearchFilterOperator::cases() as $case) {
          $enumValues[] = $case->value;
        }
        if (in_array($filterOperator, $enumValues) && isset($filterValue)) {
            if ($filterOperator === 'startswith') {
                // Escape the % character.  use = operator when searching for a specific key
                //startswith will only apply to type=key
                $filterValue = str_replace('%', '\%', $filterValue);
                $strWhere .= " WHERE `key` LIKE :starts_with_key_value";
            }
            else {
                if ($filterType === CaseSearchFilter::Key->value) {
                    $strWhere .= " WHERE BINARY `key` $filterOperator :key_value";
                }
                elseif ($filterType === CaseSearchFilter::Position->value) {
                    $strWhere .= " WHERE $this->dictName.`id` $filterOperator :key_value";
                }
                elseif ($filterType === CaseSearchFilter::Uuid->value) {
                    $strWhere .= ' WHERE `uuid` '. $filterOperator .' (UNHEX(REPLACE(:key_value' . ',"-","")))';
                }
            }
        }

        $sortOrder = $caseOptions['sort']['ascending'] ?? null;
        $orderByAscending = $sortOrder === true || $sortOrder === null;
        $orderBy = $caseOptions['sort']['order'] ?? null;

        if ($orderBy === 'key') {
            $strOrderBy = ' ORDER BY `key`';
        }
        elseif ($orderBy === 'position') {
            $strOrderBy = " ORDER BY `position`";
        }
        if (strlen($strOrderBy) > 0) {
            $strOrderBy .= $orderByAscending ? " ASC" : " DESC";
        }

        $offset = $caseOptions['offset'] ?? 0;
        $limit = $caseOptions['limit'] ?? self::MAX_CASES_PER_QUERY;
        $limit = min($limit, self::MAX_CASES_PER_QUERY);

        $strLimit = " LIMIT $limit OFFSET $offset";

        $status = $caseOptions['status'] ?? CaseStatus::All->value;

        if ($status !== CaseStatus::All->value) {
            //as per C++ code. If not all cases then only cases that are not deleted are implied
            $strWhere .= empty($strWhere) ? ' WHERE `deleted` = 0' : ' AND `deleted` = 0';
            if ($status === CaseStatus::PartialsOnly->value) {
                /** @phpstan-ignore-next-line */
                 $strWhere .= empty($strWhere) ? " WHERE `partial_save_mode` IS NOT NULL"
                               : "  AND `partial_save_mode` IS NOT NULL";
            }
            elseif ($status == CaseStatus::DuplicatesOnly->value) {
                /** @phpstan-ignore-next-line */
               if(empty($strWhere)){
                 $strWhere .= " WHERE `key` IN (SELECT `key` FROM `$this->dictName`  "
                    . "WHERE `deleted` = 0 GROUP BY `key` HAVING COUNT(*) > 1 )";
               }
               else {
                $strWhere .= " AND `key` IN (SELECT `key` FROM `$this->dictName`  "
                    . "WHERE `deleted` = 0 GROUP BY `key` HAVING COUNT(*) > 1 )";
               }
            }
        }

        $stm .= $strWhere . $strOrderBy . $strLimit;
        return $stm;
    }

    private static function clearSummaryDefaults(&$rows) {
        foreach ($rows as &$row) {
            //send only if label is not blank
            if ($row['label'] === '') {
                unset($row['label']);
            }
            //send only if deleted is true
            if ($row['deleted'] === 0) {
                unset($row['deleted']);
            }
            //send only if verified is true
            if ($row['verified'] === 0) {
                unset($row['verified']);
            }
            //send only if partialSaveMode is not null
            if ($row['partialSaveMode'] === null) {
                unset($row['partialSaveMode']);
            }
            //send only if caseNote is not blank
            if ($row['caseNote'] === '') {
                unset($row['caseNote']);
            }
        }
    }

    private static function processCaseBlobs(&$rows) {
        foreach ($rows as &$row) {
            if (isset($row['case'])) {
                $row['case'] = gzuncompress(substr($row['case'], 4));
            }
            else {
                // set cases excluded due to cache options to empty objects
                $row['case'] = '{}';
            }
        }
    }
}
