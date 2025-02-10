<?php

namespace AppBundle\Security;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\User\User;
use AppBundle\CSPro\User\User\Role;
use AppBundle\CSPro\RolesRepository;

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
                $exception = new UsernameNotFoundException('Username not found using apiKey' . $apiKey);
                $exception->setUsername(null);
                throw $exception;
            } else {
                return $this->getUser($username);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user: ' . $username, ["context" => (string) $e]);
            $exception = new UsernameNotFoundException('Failed getting user: ');
            $exception->setUsername(null);
            throw $exception;
        }
        return null;
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
            $roles[] = 'ROLE_USER';
            if ($roleId == User::ADMINISTRATOR) {
                $roles[] = 'ROLE_ADMIN';
                $roles[] = 'ROLE_'.strtoupper(\AppBundle\Security\UserVoter::USERS_ALL);
                $roles[] = 'ROLE_'.strtoupper(\AppBundle\Security\DictionaryVoter::DATA_DOWNLOAD);
                $roles[] = 'ROLE_REPORTS_ALL';
                $roles[] = 'ROLE_ROLES_ALL';
                $roles[] = 'ROLE_SETTINGS_ALL';
                $roles[] = 'ROLE_APPS_ALL';
            }
            elseif ($roleId == User::STANDARD_USER) {
                $roles[] = 'ROLE_STANDARD_USER';
                $roles[] = 'ROLE_'.strtoupper(\AppBundle\Security\DictionaryVoter::DICTIONARY_OPERATIONS);
            }

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
            $exception = new UsernameNotFoundException('Failed getting user roles: ' . $username);
            $exception->setUsername($username);
            throw $exception;
        }
        return $roles;
    }

    public function getUser($username) {
        try {
            $stm = 'SELECT username, first_name as firstName, last_name as lastName, password, email, phone, role as role
				FROM cspro_users where username = :uname';
            $bind = ['uname' => ['uname' => $username]];
            $result = $this->pdo->fetchOne($stm, $bind);
            if (!$result) {
                $exception = new UsernameNotFoundException('Username  not found ' . $username);
                $exception->setUsername($username);
                throw $exception;
            } else {

                $user = new User($username, $result['firstName'], $result['lastName'], $result['role'], $result['password'], $result['email'], $result['phone']);
                $user->setRoles($this->getUserRoles($username, $result['role'])); //used for symfony ROLE_ voter
                $user->setUserRole($this->rolesRepository->getRoleById(($result['role']))); //userRole role object has dictionary level permissions
                return $user;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting user: ' . $username, ["context" => (string) $e]);
            $exception = new UsernameNotFoundException('Failed getting user: ' . $username);
            $exception->setUsername($username);
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
