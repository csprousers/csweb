<?php

namespace AppBundle\Controller\api;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use AppBundle\CSPro\User\UserParser;
use AppBundle\CSPro\User\UserValidator;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\CSProJsonValidator;
use PDO;
use AppBundle\Security\UserVoter;
use AppBundle\CSPro\RolesRepository;

class UserController extends AbstractController implements ApiTokenAuthenticatedController {

    public const MAX_IMPORT_USERS_PER_ITERATION = 500;

    private $rolesRepository;

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    //override the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->rolesRepository = new RolesRepository($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    function isValidUser($user, $add = true) {
        // return false if any of the required attributes are not set.
        if ($add === true) { // test the user attributes for add action
            $ret = !(empty($user ['password']) || empty($user ['username']) || empty($user ['firstName']) || empty($user ['lastName']));
        } else { // for updating user empty password means ignore password update
            $ret = !(empty($user ['username']) || empty($user ['firstName']) || empty($user ['lastName']));
        }
        return $ret;
    }

    function insertUsers($users) {
        $stm = 'INSERT INTO cspro_users (username, password, first_name, last_name, email, phone, role) VALUES ';

        $values = '';
        $numUsers = is_countable($users) ? count($users) : 0;

        for ($i = 0; $i < $numUsers; $i++) {
            $user = $users[$i];
            $email = $user->getEmail();
            $phone = $user->getPhone();

            $values .= ('(');
            $values .= "'" . $user->getUsername() . "',";
            $values .= "'" . $user->getPassword() . "',";
            $values .= "'" . $user->getFirstName() . "',";
            $values .= "'" . $user->getLastName() . "',";
            if (empty(trim($email))) {
                $values .= "NULL,";
            } else {
                $values .= "'" . $email . "',";
            }
            if (empty(trim($phone))) {
                $values .= "NULL,";
            } else {
                $values .= "'" . $phone . "',";
            }

            $values .= "'" . $user->getRoleId() . "'";
            $values .= (')');

            if ($i + 1 != $numUsers)
                $values .= ",";
        }

        $stm .= $values;

        $stm .= ' ON DUPLICATE KEY UPDATE password = VALUES(password), first_name = VALUES(first_name), last_name = VALUES(last_name), email = VALUES(email), phone = VALUES(phone), role = VALUES(role)';

        $pdoStm = $this->pdo->prepare($stm);

        try {
            $result = $pdoStm->execute();
        } catch (\Exception $e) {
            $this->logger->error('Failed adding import users into: ' . 'cspro_user', ['context' => (string) $e]);
            throw new \Exception('Failed adding import users into: ' . 'cspro_user', 0, $e);
        }
    }

    // addMultipleUsers
    //TODO: refactor &&&
    function addMultipleUsers(Request $request) {
        $rolesMap = [];
        $this->logger->debug("processing users...");
        //ini changes are for the duration of the script and are restored once the script ends
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

        //create a streamed response
        $response = new StreamedResponse();
        $content = $request->getContent();

        $headerRow = $request->headers->get('x-csw-data-header');
        isset($headerRow) && $headerRow === "1" ? $headerRow = true : $headerRow = false;
        $userController = $this;
        $parser = new UserParser ();
        $roles = $this->rolesRepository->getRoles();
        foreach ($roles as $role) {
            $rolesMap[$role->name] = $role->id;
        }
        //$this->logger->debug('roles map is ' . print_r($rolesMap,true));
        $validator = new UserValidator($rolesMap, $this->logger);
        try {
            // validate import users
            $isValid = $validator->validateImportUsers($content, $headerRow);

            if ($isValid) {
                $maxusersImport = $this->getParameter('csweb_api_max_import');
                $users = $parser->parseUsers($content, $headerRow, $maxusersImport);
            } else {
                $response = new CSProResponse ();
                $strMsg = "";
                foreach ($validator->getErrors() as $error) {
                    $strMsg .= sprintf("%s<br/>", $error);
                }
                $response->setError(400, 'user_file_invalid', $strMsg);
                $response->setStatusCode(CSProResponse::HTTP_BAD_REQUEST);
                $this->logger->debug($strMsg);
                return $response;
            }
        } catch (\Exception $e) {
            ${$this}->logger->error($e->getMessage());
            $response = new CSProResponse ();
            $response->setError(400, 'user_file_invalid', $e->getMessage());
            $response->setStatusCode(CSProResponse::HTTP_BAD_REQUEST);
            return $response;
        }

        $this->logger->debug("processing users...");
        // $maxUsersImportedPerIteration - specifies the max users for each insert to sql table. For very large imports, increase this to process more user inserts for each iteration
        // you may also have to increase the php memory limit and mysql memory limit for the packet by increasing max_allowed_packet say by using MySQL SET GLOBAL max_allowed_packet=512M
        $maxUsersImportedPerIteration = UserController::MAX_IMPORT_USERS_PER_ITERATION;
        $logger = $this->logger;
        //$this->logger->debug('roles map is ' . print_r($rolesMap,true));
        $response->setCallback(function () use ($logger, $rolesMap, $maxUsersImportedPerIteration, $request, $users, $parser, $userController, $headerRow) {
            $percentComplete = null;
            $params = [];
            $userHashMap = [];
            $duplicateMsg = "<br>";
            $duplicateUserCount = 0;
            $responseDescription = "Success";
            //init the block size to 1% of the total number. If it is exceeds the $maxUsersImportedPerIteration size then use the $maxUsersImportedPerIteration 
            //to give users control for very large imports without having to increase the mysql max_allowed_packet limit
            $blockSize = isset($users) && (is_countable($users) ? count($users) : 0) ? max((is_countable($users) ? count($users) : 0) * 0.01, 50) : 50;
            $blockSize = $blockSize > $maxUsersImportedPerIteration ? $maxUsersImportedPerIteration : $blockSize;

            $usersToInsert = [];
            for ($i = 0; $i < (is_countable($users) ? count($users) : 0); $i++) {
                $user = $parser->transformUser($users[$i], $rolesMap);
                array_push($usersToInsert, $user);

                if (isset($userHashMap[$user->getUsername()])) {
                    $lineNum = ($headerRow === true) ? $i + 2 : $i + 1;
                    $duplicateMsg = $duplicateMsg . "Duplicate user: " . $user->getUsername() . " at line number: " . $lineNum . "<br>";
                    $duplicateUserCount++;
                } else {
                    $userHashMap[$user->getUsername()] = 1;
                }
                //if final block
                if ($i == ((is_countable($users) ? count($users) : 0) - 1)) {
                    $this->insertUsers($usersToInsert);
                    $percentComplete = 100;
                }
                // else if interim block
                else if ($blockSize == count($usersToInsert)) {

                    $percentComplete = round($i / (is_countable($users) ? count($users) : 0) * 100);

                    $this->insertUsers($usersToInsert);
                    unset($usersToInsert); // clear $users array
                    $usersToInsert = []; // clear $users array

                    $responseCode = $percentComplete === 100 ? 200 : 206;
                    if ($percentComplete === 100 && $duplicateUserCount > 0) {
                        $duplicateMsg = $duplicateMsg . "Total duplicate user count is: " . $duplicateUserCount . "<br>";
                        $responseDescription = $responseDescription . $duplicateMsg;
                    }
                    $strJSONResponse = json_encode(["code" => $responseCode, "description" => $responseDescription, 'progress' => $percentComplete, 'count' => $i + 1, 'status' => "Success"], JSON_THROW_ON_ERROR);
                    echo '\n' . $strJSONResponse;
                    $this->logger->debug($strJSONResponse);

                    flush();
                    $strJSONResponse = ''; //reset json string response
                }
            }
            $responseCode = $percentComplete === 100 ? 200 : 206;
            if ($percentComplete === 100 && $duplicateUserCount > 0) {
                $duplicateMsg = $duplicateMsg . "Total duplicate user count is: " . $duplicateUserCount . "<br>";
                $responseDescription = $responseDescription . $duplicateMsg;
            }
            $strJSONResponse = json_encode(["code" => $responseCode, "description" => $responseDescription, 'progress' => $percentComplete, 'count' => $i, 'status' => "Success"], JSON_THROW_ON_ERROR);
            echo '\n' . $strJSONResponse;
            $logger->debug($strJSONResponse);

            flush();
            $strJSONResponse = ''; //reset json string response
        }
        );
        $response->headers->set('Content-Type', 'application/json');
        return $response->send();
    }

    // addSingleUser
    function addSingleUser(Request $request) {
        $username = null;
        $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
        $params = [];
        $content = $request->getContent();
        $response = new CSProResponse ();
        if (empty($content)) {
            $response->setError(400, 'user_invalid_request', 'Invalid request. Missing JSON content.');
            return $response;
        } else {
            $uri = '#/definitions/User';
            $csproJsonValidator = new CSProJsonValidator($this->logger);
            $csproJsonValidator->validateEncodedJSON($content, $uri);

            $params = json_decode($content, true, 512, JSON_THROW_ON_ERROR); // 2nd param to get as array
            if (!$this->isValidUser($params)) {
                $response->setError(400, 'user_invalid', 'Invalid User Supplied. User attributes cannot be blank or invalid.');
                return $response;
            }
        }
        try {
            $stm = 'SELECT username  FROM cspro_users WHERE username = :uname;';
            $username = strtolower($params ['username']);
            $bind = ['uname' => ['uname' => $username]];
            if ($this->pdo->fetchValue($stm, $bind)) {
                $response->setError(409, 'user_name_exists', 'Username already in use');
                return $response;
            }

            if (!isset($params ['role'])) {
                $params ['role'] = 1; // Standard User
            }

            $email = $params ['email'];
            $phone = $params ['phone'];
            if (empty(trim($email))) {
                $email = null;
            }
            if (empty(trim($phone))) {
                $phone = null;
            }

            $user = new \AppBundle\CSPro\User\User($params['username'], $params['firstName'], $params['lastName'], $params['role'], $params['password'], $email, $phone);
            $parser = new UserParser();
            $user = $parser->transformUser($user);
            $users = [];
            array_push($users, $user);
            $this->insertUsers($users);
        } catch (\Exception $e) {
            $this->logger->error('Failed adding user: ' . $username, ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError(500, 'user_add_error', 'Failed adding user');
        }
        $response = new CSProResponse(json_encode(["code" => 200, "description" => "Success"]), CSProResponse::HTTP_OK);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // addUser
    #[Route('/users', methods: ['POST'])]
            function addUser(Request $request): CSProResponse {

        // $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);

        $response = new CSProResponse();
        // content type
        $cType = $request->headers->get('Content-Type');
        $this->logger->debug("Content Type: " . $cType);
        if (str_contains($cType, 'json')) {
            $this->logger->debug("********JSON contentType:" . $cType . "*******");
            $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
            $response = $this->addSingleUser($request);
        } else if (str_contains($cType, 'text/plain')) {
            $this->logger->debug("********TXT contentType:" . $cType . "*******");
            $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
            $response = $this->addMultipleUsers($request);
        } else {
            $errMsg = "Failed adding user. Content-Type:" . $cType . " must be either json or txt";
            $this->logger->error($errMsg);
            $response = new CSProResponse();
            $response->setError(500, 'user_add_error', $errMsg);
        }

        return $response;
    }

    // get users

    #[Route('/users', methods: ['GET'])]
            function getUserList(Request $request): CSProResponse {
        $result = [];
        $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
        $userCount = 0;
        $usersFiltered = 0;

        $start = $request->headers->get('x-csw-user-start');
        if ($start == null || $start == "") {
            $start = 0;
        }

        $length = $request->headers->get('x-csw-user-length');
        if ($length == null || $length == "") {
            $length = 1000;
        }

        $search = $request->headers->get('x-csw-user-search');

        $orderColumn = $request->headers->get('x-csw-user-order-column');
        if ($orderColumn == null || $orderColumn == "") {
            $orderColumn = 1;
        } else {
            $orderColumn++; // SQL doesn't use 0 column as the first column
        }

        $orderDirection = $request->headers->get('x-csw-user-order-direction');
        if ($orderDirection == null || $orderDirection == "") {
            $orderDirection = "ASC";
        }

        //users for Table
        try {
            $argArray = [];

            $selectStm = "SELECT username, first_name as firstName, last_name as lastName, email, phone, role as role FROM cspro_users ";

            $searchTF = false;
            if ($search != null && $search != "" && $search != " ") {
                $searchTF = true;
                $searchStm = " WHERE username LIKE :uname OR first_name LIKE :fname OR last_name LIKE :lname OR email LIKE :email OR phone LIKE :phone ";
                $selectStm = $selectStm . $searchStm;
            }

            if (strtolower($orderDirection) == 'asc') {
                $orderByStm = ' ORDER BY :column ASC LIMIT :length OFFSET :start ';
                $selectStm = $selectStm . $orderByStm;
            } else {
                $orderByStm = ' ORDER BY :column DESC LIMIT :length OFFSET :start ';
                $selectStm = $selectStm . $orderByStm;
            }

            //$this->logger->debug( '********** query: ' . $selectStm);

            $query = $this->pdo->prepare($selectStm);

            if ($searchTF) {
                $query->bindValue(':uname', "%" . $search . "%", PDO::PARAM_STR);
                $query->bindValue(':fname', "%" . $search . "%", PDO::PARAM_STR);
                $query->bindValue(':lname', "%" . $search . "%", PDO::PARAM_STR);
                $query->bindValue(':email', "%" . $search . "%", PDO::PARAM_STR);
                $query->bindValue(':phone', "%" . $search . "%", PDO::PARAM_STR);
            }
            $query->bindValue(':column', (int) $orderColumn, PDO::PARAM_INT);
            $query->bindValue(':length', (int) $length, PDO::PARAM_INT);
            $query->bindValue(':start', (int) $start, PDO::PARAM_INT);

            $query->execute();
            $result = $query->fetchAll();

            $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user list', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting user list';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'users_get_error', $result ['description']);
        }

        //usersCount
        try {
            $stmCount = 'SELECT COUNT(*) FROM cspro_users';
            $query = $this->pdo->prepare($stmCount);
            $query->execute();
            $userCount = $query->fetch();
        } catch (\Exception $e) {
            $this->logger->error('\n\nFailed getting user count', ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting user count';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'users_get_error', $result ['description']);
        }

        //usersFiltered
        try {
            $stmSearchCount = 'SELECT COUNT(*) FROM cspro_users';

            $search = $request->headers->get('x-csw-user-search');
            $search ??= "";
            $bindParam = [];
            if (trim($search) != "") {
                $searchStm = " WHERE username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?";
                $stmSearchCount = $stmSearchCount . $searchStm;
                $bindParam = ["%" . $search . "%", "%" . $search . "%", "%" . $search . "%", "%" . $search . "%", "%" . $search . "%"];
            }

            $query = $this->pdo->prepare($stmSearchCount);
            $query->execute($bindParam);
            $usersFiltered = $query->fetch();
        } catch (\Exception $e) {
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting user count';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'users_get_error', $result ['description']);
            $this->logger->error('Failed getting user count', ["context" => (string) $e]);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        $response->headers->set('x-csw-user-count', $userCount);
        $response->headers->set('x-csw-users-filtered', $usersFiltered);
        return $response;
    }

    #[Route('/users/{username}', methods: ['GET'])]
            function getUserAction(Request $request, $username): CSProResponse {
        $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
        try {
            $stm = 'SELECT username, first_name as firstName, last_name as lastName, email, phone, role as role
				FROM cspro_users where username = :uname';
            $bind = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchAll($stm, $bind);
            if (!$result) {
                $response = new CSProResponse ();
                $response->setError(404, 'user_not_found', 'User not found');
                return $response;
            } else {
                $resultUser = $result [0];
                $response = new CSProResponse(json_encode($resultUser, JSON_THROW_ON_ERROR));
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user: ' . \USERNAME, ["context" => (string) $e]);
            $response = new CSProResponse();
            $response->setError(500, 'user_get_error', 'Failed getting user');
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // Update User

    #[Route('/users/{username}', methods: ['PUT'])]
            function updateUser(Request $request, $username): CSProResponse {
        $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
        $params = [];
        $content = $request->getContent();
        $response = new CSProResponse ();

        if (empty($content)) {
            $response->setError(400, null, 'Missing JSON content in the request');
            return $response;
        } else {
            $uri = '#/definitions/User';
            $csproJsonValidator = new CSProJsonValidator($this->logger);
            $csproJsonValidator->validateEncodedJSON($content, $uri);

            $params = json_decode($content, true, 512, JSON_THROW_ON_ERROR); // 2nd param to get as array
            if (!$this->isValidUser($params, false)) {
                $response->setError(400, 'invalid_user', 'Invalid User Supplied. User attributes cannot be blank or invalid.');
                return $response;
            }
        }
        try {
            $stm = 'SELECT username , role  FROM cspro_users WHERE username = :uname;';
            $bind = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchAll($stm, $bind);
            if (count($result) == 0) {
                $response->setError(404, 'user_not_found', 'User not found');
                return $response;
            }
            $userrole = $params ['role'] ?? $result ['role'];
            if (!empty($params ['password'])) {
                $stmt = $this->pdo->prepare("UPDATE cspro_users
	                               SET username=:uname, password=:pass, first_name=:fname, last_name=:lname, email=:email, phone=:phone, role=:role
	                               WHERE username=:origuname");
            } else {
                $stmt = $this->pdo->prepare("UPDATE cspro_users
	                               SET username=:uname, first_name=:fname, last_name=:lname, email=:email, phone=:phone, role=:role
	                               WHERE username=:origuname");
            }
            $stmt->bindParam(':uname', $params ['username']);
            if (!empty($params ['password'])) {
                $passwordHash = password_hash($params ['password'], PASSWORD_DEFAULT);
                $stmt->bindParam(':pass', $passwordHash);
            }
            $email = $params ['email'];
            $phone = $params ['phone'];
            if (empty(trim($email))) {
                $email = null;
            }
            if (empty(trim($phone))) {
                $phone = null;
            }
            $stmt->bindParam(':fname', $params ['firstName']);
            $stmt->bindParam(':lname', $params ['lastName']);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':origuname', $username);
            $stmt->bindParam(':role', $userrole);
            $stmt->execute();
            $response = new CSProResponse(json_encode(["code" => 200, "description" => 'The user ' . $username . ' was successfully updated.'], JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Failed updating user: ' . \USERNAME, ["context" => (string) $e]);
            $result ['code'] = 500;
            $result ['description'] = 'Failed updating user';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'user_update_failed', $result ['description']);
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // Delete User

    #[Route('/users/{username}', methods: ['DELETE'])]
            function deleteUser(Request $request, $username): CSProResponse {
        $result = [];
        $this->denyAccessUnlessGranted(UserVoter::USERS_ALL);
        try {
            $this->pdo->beginTransaction();
            $stm = 'DELETE FROM cspro_users WHERE username = :username';
            $bind = ['username' => ['username' => $username]];
            $row_count = $this->pdo->fetchAffected($stm, $bind);

            if ($row_count == 1) {
                $this->pdo->commit();
                $result ['code'] = 200;
                $result ['description'] = 'The user ' . $username . ' was successfully deleted.';
                $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR));
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                $result ['code'] = 404;
                $result ['description'] = 'The username ' . $username . ' was not found.';
                $response = new CSProResponse ();
                $response->setError($result ['code'], 'user_delete_failed', $result ['description']);
            }
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error('Failed deleting user: ' . $username, ["context" => (string) $e]);
            $result ['code'] = 404;
            $result ['description'] = 'The user ' . $username . ' was not deleted.';
            $response = new CSProResponse ();
            $response->setError($result ['code'], 'user_delete_failed', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
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
