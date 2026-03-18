<?php

namespace App\Controller\api;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\CSPro\User\UserParser;
use App\CSPro\User\UserValidator;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use App\Service\OAuthHelper;
use App\CSPro\CSProUtils;
use App\CSPro\CSProResponse;
use App\CSPro\CSProJsonValidator;
use PDO;
use App\Security\UserVoter;
use App\CSPro\RolesRepository;

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

    // -------------------------------------------------------------------------
    // isValidUser() removed replaced by User::fromArray() + validateUser()
    // -------------------------------------------------------------------------

    private function getRolesMap(): array {
        $rolesMap = [];
        foreach ($this->rolesRepository->getRoles() as $role) {
            $rolesMap[$role->name] = $role->id;
        }
        return $rolesMap;
    }

    function insertUsers($users) {
        $colNames = ['uuid', 'username', 'password', 'first_name', 'last_name', 'email', 'phone', 'role'];
        $stm = 'INSERT INTO `cspro_users` (' . implode(',', array_map(fn($col) => "`$col`", $colNames)) . ') VALUES ';

        $numUsers = is_countable($users) ? count($users) : 0;
        $insertQuery = [];
        $insertData = [];
        for ($i = 0; $i < $numUsers; $i++) {
            $insertQuery [] =  '(' . implode(',', array_map(fn($col) => ":$col$i", $colNames)) . ')';
            $user = $users[$i];

            $insertData['uuid' . $i] = CSProUtils::guidv4();
            $insertData['username' . $i] = $user->getUsername();
            $insertData['password' . $i] = $user->getPassword();
            $insertData['first_name' . $i] = $user->getFirstName();
            $insertData['last_name' . $i] = $user->getLastName();
            $insertData['role' . $i] = $user->getRoleId();

            $email = $user->getEmail();
            $phone = $user->getPhone();
            $insertData['email' . $i] = ($email !== null && trim($email) !== '') ? trim($email) : null;
            $insertData['phone' . $i] = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;
        }

        if (!empty($insertQuery)) {
            $stm .= implode(', ', $insertQuery);
            $colNamesForDuplicate = ['password', 'first_name', 'last_name', 'email', 'phone', 'role'];
            $stm .= ' ON DUPLICATE KEY UPDATE ';
            $stm .= implode(',', array_map(fn($col) => "`$col`=VALUES(`$col`)", $colNamesForDuplicate));
            $stm .= ';';

            try {
                $stmt = $this->pdo->prepare($stm);
                $result = $stmt->execute($insertData); // true if successful
            }
            catch (\Exception $e) {
                $this->logger->error('Failed adding import users into cspro_users', ['context' => (string) $e]);
                throw new \Exception('Failed adding import users into cspro_users', 0, $e);
            }
        }
    }

    // addMultipleUsers
    //TODO: refactor &&&
    function addMultipleUsers(Request $request) {
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

        $parser = new UserParser ();
        $rolesMap = $this->getRolesMap();
        $validator = new UserValidator($rolesMap, $this->logger);
        try {
            // validate import users
            $isValid = $validator->validateImportUsers($content, $headerRow);

            if ($isValid) {
                $maxusersImport = $this->getParameter('csweb_api_max_import');
                $users = $parser->parseUsers($content, $headerRow, $maxusersImport);
            } else {
                $response = new CSProResponse();
                $strMsg = implode('<br/>', $validator->getErrors());
                $response->setError(400, 'user_file_invalid', $strMsg);
                $response->setStatusCode(CSProResponse::HTTP_BAD_REQUEST);
                $this->logger->debug($strMsg);
                return $response;
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
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
        $response->setCallback(function () use ($logger, $rolesMap, $maxUsersImportedPerIteration, $users, $parser, $headerRow) {
            $percentComplete = null;
            $usersToInsert      = [];
            $userHashMap = [];
            $duplicateMsg = "<br>";
            $duplicateUserCount = 0;
            $responseDescription = "Success";
            //init the block size to 1% of the total number. If it is exceeds the $maxUsersImportedPerIteration size then use the $maxUsersImportedPerIteration
            //to give users control for very large imports without having to increase the mysql max_allowed_packet limit
            $blockSize = isset($users) && (is_countable($users) ? count($users) : 0) ? max((is_countable($users) ? count($users) : 0) * 0.01, 50) : 50;
            $blockSize = $blockSize > $maxUsersImportedPerIteration ? $maxUsersImportedPerIteration : $blockSize;

            for ($i = 0; $i < (is_countable($users) ? count($users) : 0); $i++) {
                $user = $parser->transformUser($users[$i], $rolesMap);
                $usersToInsert[] = $user;

                if (isset($userHashMap[$user->getUsername()])) {
                    $lineNum = ($headerRow === true) ? $i + 2 : $i + 1;
                    $duplicateMsg .= "Duplicate user: " . $user->getUsername() . " at line number: " . $lineNum . "<br>";
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

    // -------------------------------------------------------------------------
    // addSingleUser now uses User::fromArray() + UserValidator::validateUser()
    // -------------------------------------------------------------------------
    function addSingleUser(Request $request) {
        $this->denyAccessUnlessGranted(UserVoter::USERS_WRITE);

        $response = new CSProResponse();
        $content  = $request->getContent();

        if (empty($content)) {
            $response->setError(400, 'user_invalid_request', 'Invalid request. Missing JSON content.');
            return $response;
        }

        // 1. Validate JSON schema (existing behaviour)
        $csproJsonValidator = new CSProJsonValidator($this->logger);
        $csproJsonValidator->validateEncodedJSON($content, '#/definitions/User');

        // 2. Decode JSON throws \JsonException on malformed input
        try {
            $params = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('addSingleUser: malformed JSON', ['context' => (string) $e]);
            $response->setError(400, 'user_invalid_request', 'Malformed JSON: ' . $e->getMessage());
            return $response;
        }

        // 3. Structural check throws \InvalidArgumentException if required keys absent/wrong type
        try {
            $user = \App\CSPro\User\User::fromArray($params);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('addSingleUser: structural error', ['context' => (string) $e]);
            $response->setError(400, 'user_invalid', $e->getMessage());
            return $response;
        }

        // 4. Default role if not supplied
        if ($user->getRoleId() === null) {
            $user->setRoleId(\App\CSPro\User\User::STANDARD_USER);
        }

        // 5. Business-rule validation (sanitizes user in place)
        $rolesMap  = $this->getRolesMap();
        $validator = new UserValidator($rolesMap, $this->logger);

        if (!$validator->validateUser($user, false)) {
            $errMsg = implode(' ', $validator->getErrors());
            $this->logger->debug('addSingleUser validation failed: ' . $errMsg);
            $response->setError(400, 'user_invalid', $errMsg);
            return $response;
        }

        // 6. Check for duplicate username
        try {
            $stm  = 'SELECT username FROM cspro_users WHERE username = :uname;';
            $bind = ['uname' => ['uname' => $user->getUsername()]];
            if ($this->pdo->fetchValue($stm, $bind)) {
                $response->setError(409, 'user_name_exists', 'Username already in use');
                return $response;
            }

            // 7. Hash password and insert
            $parser = new UserParser();
            $user   = $parser->transformUser($user);
            $this->insertUsers([$user]);
        } catch (\Exception $e) {
            $this->logger->error('Failed adding user: ' . $user->getUsername(), ['context' => (string) $e]);
            $response = new CSProResponse();
            $response->setError(500, 'user_add_error', 'Failed adding user');
            return $response;
        }

        $response = new CSProResponse(
            json_encode(['code' => 200, 'description' => 'Success'], JSON_THROW_ON_ERROR),
            CSProResponse::HTTP_OK
        );
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // addUser
    #[Route('/users', methods: ['POST'])]
    function addUser(Request $request): CSProResponse {
        $this->denyAccessUnlessGranted(UserVoter::USERS_WRITE);
        $response = new CSProResponse();
        $cType    = $request->headers->get('Content-Type');
        $this->logger->debug("Content Type: " . $cType);

        if (str_contains($cType, 'json')) {
            $response = $this->addSingleUser($request);
        } elseif (str_contains($cType, 'text/plain')) {
            $response = $this->addMultipleUsers($request);
        } else {
            $errMsg = "Failed adding user. Content-Type: $cType must be either json or text/plain";
            $this->logger->error($errMsg);
            $response->setError(500, 'user_add_error', $errMsg);
        }

        return $response;
    }

    // get users

    #[Route('/users', methods: ['GET'])]
            function getUserList(Request $request): CSProResponse {
        $result = [];
        $this->denyAccessUnlessGranted(UserVoter::USERS_READ);
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
        $searchTF = false;
        try {
            $selectStm = "SELECT `uuid`, username, first_name as firstName, last_name as lastName, email, phone, role as role FROM cspro_users ";

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
                $searchVal = '%' . $search . '%';
                $query->bindValue(':uname', $searchVal, PDO::PARAM_STR);
                $query->bindValue(':fname', $searchVal, PDO::PARAM_STR);
                $query->bindValue(':lname', $searchVal, PDO::PARAM_STR);
                $query->bindValue(':email', $searchVal, PDO::PARAM_STR);
                $query->bindValue(':phone', $searchVal, PDO::PARAM_STR);
            }
            $query->bindValue(':column', (int)$orderColumn, PDO::PARAM_INT);
            $query->bindValue(':length', (int)$length,      PDO::PARAM_INT);
            $query->bindValue(':start',  (int)$start,       PDO::PARAM_INT);
            $query->execute();

            $result   = $query->fetchAll(PDO::FETCH_ASSOC);
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
            $query = $this->pdo->prepare('SELECT COUNT(*) FROM cspro_users');
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
    // Update User

    #[Route('/users/{username}', methods: ['PUT'])]
    function updateUser(Request $request, $username): CSProResponse {
        $this->denyAccessUnlessGranted(UserVoter::USERS_WRITE);

        $response = new CSProResponse();
        $content  = $request->getContent();

        if (empty($content)) {
            $response->setError(400, null, 'Missing JSON content in the request');
            return $response;
        }

        // 1. Schema validation (existing behaviour)
        $csproJsonValidator = new CSProJsonValidator($this->logger);
        $csproJsonValidator->validateEncodedJSON($content, '#/definitions/User');

        // 2. Decode JSON
        try {
            $params = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('updateUser: malformed JSON', ['context' => (string) $e]);
            $response->setError(400, 'user_invalid_request', 'Malformed JSON: ' . $e->getMessage());
            return $response;
        }

        // 3. Structural check
        try {
            $user = \App\CSPro\User\User::fromArray($params);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('updateUser: structural error', ['context' => (string) $e]);
            $response->setError(400, 'user_invalid', $e->getMessage());
            return $response;
        }

        // 4. Business-rule validation isUpdate=true so password is optional
        $rolesMap  = $this->getRolesMap();
        $validator = new UserValidator($rolesMap, $this->logger);

        if (!$validator->validateUser($user, true)) {
            $errMsg = implode(' ', $validator->getErrors());
            $this->logger->debug('updateUser validation failed: ' . $errMsg);
            $response->setError(400, 'invalid_user', $errMsg);
            return $response;
        }

        try {
            // 5. Confirm the target user exists and get their current role
            $stm    = 'SELECT username, role FROM cspro_users WHERE username = :uname;';
            $bind   = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchAll($stm, $bind);

            if (count($result) === 0) {
                $response->setError(404, 'user_not_found', 'User not found');
                return $response;
            }

            // BUG FIX: fetchAll returns an array of rows, so the current role
            // is at $result[0]['role'], not $result['role'].
            $currentRole = $result[0]['role'];
            $userrole    = $user->getRoleId() ?? $currentRole;

            $password        = $user->getPassword();
            $passwordChanged = ($password !== null && $password !== '');

            if ($passwordChanged) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare(
                    "UPDATE cspro_users
                     SET username=:uname, password=:pass, first_name=:fname,
                         last_name=:lname, email=:email, phone=:phone, role=:role
                     WHERE username=:origuname"
                );
                $stmt->bindParam(':pass', $passwordHash);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE cspro_users
                     SET username=:uname, first_name=:fname,
                         last_name=:lname, email=:email, phone=:phone, role=:role
                     WHERE username=:origuname"
                );
            }

            // Sanitized values already set on $user by validateUser()
            $updatedUsername = $user->getUsername();
            $firstName       = $user->getFirstName();
            $lastName        = $user->getLastName();
            $email           = ($user->getEmail() !== '' ? $user->getEmail() : null);
            $phone           = ($user->getPhone() !== '' ? $user->getPhone() : null);

            $stmt->bindParam(':uname',     $updatedUsername);
            $stmt->bindParam(':fname',     $firstName);
            $stmt->bindParam(':lname',     $lastName);
            $stmt->bindParam(':email',     $email);
            $stmt->bindParam(':phone',     $phone);
            $stmt->bindParam(':origuname', $username);
            $stmt->bindParam(':role',      $userrole);
            $stmt->execute();

            $response = new CSProResponse(
                json_encode([
                    'code'        => 200,
                    'description' => "The user $username was successfully updated.",
                ], JSON_THROW_ON_ERROR),
                CSProResponse::HTTP_OK
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed updating user: ' . $username, ['context' => (string) $e]);
            $response->setError(500, 'user_update_failed', 'Failed updating user');
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // Delete User

    #[Route('/users/{username}', methods: ['DELETE'])]
    function deleteUser(Request $request, $username): CSProResponse {
        $result   = [];
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        $this->denyAccessUnlessGranted(UserVoter::USERS_WRITE);
        try {
            $this->pdo->beginTransaction();
            $stm       = 'DELETE FROM cspro_users WHERE username = :username';
            $bind      = ['username' => ['username' => $username]];
            $row_count = $this->pdo->fetchAffected($stm, $bind);

            if ($row_count == 1) {
                $this->pdo->commit();
                $result['code']        = 200;
                $result['description'] = "The user $username was successfully deleted.";
                $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR));
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                $result['code']        = 404;
                $result['description'] = "The username $username was not found.";
                $response = new CSProResponse();
                $response->setError($result['code'], 'user_delete_failed', $result['description']);
            }
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error('Failed deleting user: ' . $username, ['context' => (string) $e]);
            $response = new CSProResponse();
            $response->setError(404, 'user_delete_failed', "The user $username was not deleted.");
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/users/{username}', methods: ['GET'])]
    function getUserAction(Request $request, $username): CSProResponse {
        $this->denyAccessUnlessGranted(UserVoter::USERS_READ);
        try {
            $stm    = 'SELECT uuid, username, first_name as firstName, last_name as lastName, email, phone, role
                       FROM cspro_users WHERE username = :uname';
            $bind   = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchAll($stm, $bind);

            if (!$result) {
                $response = new CSProResponse();
                $response->setError(404, 'user_not_found', 'User not found');
                return $response;
            }
            $response = new CSProResponse(json_encode($result[0], JSON_THROW_ON_ERROR));
        } catch (\Exception $e) {
            $this->logger->error("Failed getting user: $username", ['context' => (string) $e]);
            $response = new CSProResponse();
            $response->setError(500, 'user_get_error', 'Failed getting user');
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
        $keys   = [];
        $values = $params;
        foreach ($params as $key => $value) {
            $keys[] = is_string($key) ? '/:' . $key . '/' : '/[?]/';
            if (is_array($value))  $values[$key] = implode(',', $value);
            if (is_null($value))   $values[$key] = 'NULL';
        }
        array_walk($values, function (&$v) {
            if (!is_numeric($v) && $v != 'NULL') $v = "'" . $v . "'";
        });
        return preg_replace($keys, $values, $query, 1);
    }

}
