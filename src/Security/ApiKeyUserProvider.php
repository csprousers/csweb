<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Psr\Log\LoggerInterface;
use App\Service\PdoHelper;
use App\CSPro\User\User;
use App\CSPro\RolesRepository;

class ApiKeyUserProvider implements UserProviderInterface {

    private $rolesRepository;
    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger) {
        $this->rolesRepository = new RolesRepository($this->pdo, $this->logger);
    }

    //getUser based on apiKey
    public function loadUserByApiKey($apiKey) {
        try {
            $stm = 'SELECT user_id FROM oauth_access_tokens where access_token = :apiKey';
            $bind = ['apiKey' => ['apiKey' => $apiKey]];
            $username = $this->pdo->fetchValue($stm, $bind);
            if (!$username) {
                $exception = new UserNotFoundException('Username not found using apiKey' . $apiKey);
                $exception->setUserIdentifier(null);
                throw $exception;
            } else {
                return $this->getUser($username);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user: ' . ($username ?? 'unknown'), ["context" => (string) $e]);
            $exception = new UserNotFoundException('Failed getting user: ');
            $exception->setUserIdentifier(null);
            throw $exception;
        }
        return null;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface {
        return $this->loadUserByUsername($identifier);
    }

    public function loadUserByUsername($username) {
        return $this->getUser($username);
    }

    private function getUserRoles($username, $roleId) {
        $roles = [];
        try {
            $stm = 'SELECT name as permission_name FROM cspro_role_permissions JOIN cspro_permissions ON permission_id  = cspro_permissions.id
				where role_id = :roleId';
            $bind = [];
            $bind['roleId'] = $roleId;
            $result = $this->pdo->fetchAll($stm, $bind);
            //for each role found add to array
            $n = 0;
            while ($n < count($result)) {
                $rolename = 'ROLE_' . strtoupper($result[$n]['permission_name']);
                $roles[] = $rolename;
                $n++;
            }
            return $roles;
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user roles', ["context" => (string) $e]);
            $exception = new UserNotFoundException('Failed getting user roles: ' . $username);
            $exception->setUserIdentifier($username);
            throw $exception;
        }
        //these roles cannot be modified
         if ($roleId == User::ADMINISTRATOR) {
            //admin defaults that are true:login access, users, roles, apps, dictionary, data, files, messages, reports, paradata
            $roles[] = 'ROLE_ADMIN';
        } elseif ($roleId == User::DEVELOPER) {
            //developer defaults that are true:login access, users, apps, dictionary, data.read, data.write, data.clear.dashboard, files, messages, reports
            $roles[] = 'ROLE_DEVELOPER';
        } elseif ($roleId == User::STANDARD_USER) {
            // defaults that are true:apps.read, dictionary.read, data.read, data.write, files, messages, paradata
            $roles[] = 'ROLE_STANDARD_USER';
        }
        $this->logger->debug(json_encode($roles));
        return $roles;
    }

    public function getUser($username) {
        try {
            $stm = 'SELECT `uuid`, username, first_name as firstName, last_name as lastName, password, email, phone, role as role
				FROM cspro_users where username = :uname';
            $bind = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchOne($stm, $bind);
            if (!$result) {
                $exception = new UserNotFoundException('Username  not found ' . $username);
                $exception->setUserIdentifier($username);
                throw $exception;
            } else {

                $user = new User($result['uuid'], $username, $result['firstName'], $result['lastName'], $result['role'], $result['password'], $result['email'], $result['phone']);
                $user->setRoles($this->getUserRoles($username, $result['role'])); //used for symfony ROLE_ voter
                $user->setUserRole($this->rolesRepository->getRoleById(($result['role']))); //userRole role object has dictionary level permissions
                //$this->logger->debug(json_encode($user->getUserRole()));
                $this->logger->debug($user->getUserRole()->toJSON());
                return $user;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user: ' . $username, ["context" => (string) $e]);
            $exception = new UserNotFoundException('Failed getting user: ' . $username);
            $exception->setUserIdentifier($username);
            throw $exception;
        }
    }

    public function refreshUser(UserInterface $user) {
        // this is used for storing authentication in the session
        // butt he token is sent in each request,
        // so authentication can be stateless. Throwing this exception
        // is proper to make things stateless
        throw new UnsupportedUserException();
    }

    public function supportsClass($class) {
        return User::class === $class;
    }

}
