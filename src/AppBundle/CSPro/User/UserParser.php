<?php

namespace AppBundle\CSPro\User;

use AppBundle\CSPro\User\User;

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

        $users = [];
        $csv = [];

        $lines = explode("\n", $content);
        $lineCount = count($lines);
        $startLine = 0;
        if ($headerRow === true) {
            $startLine = 1;
        }

        // why "maxUsers + 1" you say? Apparently an extra line is added during the above explode() call.
        if ($lineCount > $maxUsers + 1)
            throw new \Exception("The maximum allowable number of users to be created has been exceeded. There is a limit of " . $maxUsers . " users that can be created at one time. Please break the file into smaller files and proceed.", 0);

        for ($i = $startLine; $i < $lineCount; $i++) {

            $csv = str_getcsv($lines[$i], ",", '"', "\n");
            //set the user attributes to null if they are not set
            for ($attrIndex = 0; $attrIndex < 7; $attrIndex++) {
                if (!isset($csv[$attrIndex])){
                    $csv[$attrIndex] = null;
                }
                else {
                    $csv[$attrIndex] = trim($csv[$attrIndex]);
                }
            }

            if ($csv[0] != null) {
                if (empty(trim($csv[5])))
                    $csv[5] = null; //email
                if (empty(trim($csv[6])))
                    $csv[6] = null; //phone

                $user = new User($csv[0], $csv[1], $csv[2], $csv[3], $csv[4], $csv[5], $csv[6]);
                array_push($users, $user);
            } else {
                break;
            }
        }

        return $users;
    }

}
