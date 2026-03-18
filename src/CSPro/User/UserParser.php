<?php

namespace App\CSPro\User;

use App\CSPro\CSProUtils;
use App\CSPro\User\User;

class UserParser {

    protected $passWordHashMap = [];

    function calculateHashCost($logger) {
        $timeTarget = 0.001;

        $cost = 3;
        do {
            $cost++;
            $start = microtime(true);
            password_hash("test", PASSWORD_BCRYPT, ["cost" => $cost]);
            $end = microtime(true);
        } while (($end - $start) < $timeTarget);

        $logger->debug("Appropriate Cost Found: " . $cost);
    }

    // transform the users
    function transformUser($user, $rolesMap = null) {

        if (isset($rolesMap)) {
            $roleId = $rolesMap[$user->getRoleId()];
            if (isset($roleId)) {
                $user->setRoleId($roleId);
            }
        }

        $options = [
            'cost' => 8
        ];

        if (!isset($this->passWordHashMap[$user->getPassword()])) {//if hash not found create and store
            $passwordHash = password_hash($user->getPassword(), PASSWORD_BCRYPT, $options);
            $this->passWordHashMap[$user->getPassword()] = $passwordHash;
            $user->setPassword($passwordHash);
        } else {//use the stored hash
            $passwordHash = $this->passWordHashMap[$user->getPassword()];
            $user->setPassword($passwordHash);
        }

        return $user;
    }

    // parse and transform users, return users array
    function parseUsers($content, $headerRow, $maxUsersImport = 100) {
        $maxUsers = $maxUsersImport;
        $users    = [];

        // Match validateImportUsers: strip blank lines first, then re-index
        // so both passes operate on the same set of lines.
        // NOTE: reported line numbers in errors may be off by the count of
        // any blank lines preceding the row, which is an accepted tradeoff
        // for the outlier case of blank lines mid-file.
        $content   = preg_replace('/^[ \t]*[\r\n]+/m', '', $content);
        $lines     = array_values(array_filter(explode("\n", $content), 'strlen'));
        $lineCount = count($lines);

        $startLine = $headerRow === true ? 1 : 0;

        // Check max users against data rows only (header excluded)
        $dataRowCount = $headerRow ? $lineCount - 1 : $lineCount;
        if ($dataRowCount > $maxUsers) {
            throw new \Exception(
                "The maximum allowable number of users to be created has been exceeded. " .
                "There is a limit of " . $maxUsers . " users that can be created at one time. " .
                "Please break the file into smaller files and proceed.", 0
            );
        }

        for ($i = $startLine; $i < $lineCount; $i++) {
            $csv = str_getcsv($lines[$i], ',', '"');

            // Normalise to exactly 7 slots; trim and convert empty strings to null.
            // Columns: 0=username, 1=firstName, 2=lastName, 3=roleId,
            //          4=password, 5=email (optional), 6=phone (optional)
            for ($col = 0; $col < 7; $col++) {
                if (!isset($csv[$col]) || trim($csv[$col]) === '') {
                    $csv[$col] = null;
                } else {
                    $csv[$col] = trim($csv[$col]);
                }
            }

            // Empty first column means end of data
            if ($csv[0] === null) {
                break;
            }
            $user    = new User(CSProUtils::guidv4(), $csv[0], $csv[1], $csv[2], $csv[3], $csv[4], $csv[5], $csv[6]);
            $users[] = $user;
        }

        return $users;
    }

}
