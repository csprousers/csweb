<?php

namespace App\Security;

use App\CSPro\User\User;
use App\CSPro\User\Role;
use App\CSPro\User\RoleDictionaryPermissions;
use App\CSPro\Dictionary;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of DictionaryVoter
 *
 * @author savy
 */
class DictionaryVoter extends Voter {

    public const DICTIONARIES_ALL = 'dictionaries';
    public const DICTIONARIES_READ = 'dictionaries.read';
    public const DICTIONARIES_WRITE = 'dictionaries.write'; //write means delete / overwrite
    public const DATA_ALL = 'data';
    public const DATA_READ = 'data.read';
    public const DATA_WRITE = 'data.write';
    public const DATA_CLEAR = 'data.clear'; //delete data using API
    public const DATA_CLEAR_DASHBOARD = 'data.clear.dashboard'; //delete data using dashboard ui
    public const DATA_NONE = 'data.none'; //none - no permissions are set. A case where the user does not want defaults and does not want to set any permissions

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject): bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::DICTIONARIES_ALL, self::DICTIONARIES_READ, self::DICTIONARIES_WRITE, self::DATA_ALL, self::DATA_READ, self::DATA_WRITE, self::DATA_CLEAR, self::DATA_CLEAR_DASHBOARD])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token): bool {
        $user = $token->getUser();
        $this->logger->debug('Dictionary voter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }

        $dictName = $subject;
        return match ($attribute) {
            self::DICTIONARIES_READ => $this->canReadDictionaries($user, $attribute),
            self::DICTIONARIES_WRITE => $this->canWriteDictionaries($user, $attribute),
            self::DATA_READ => $this->canReadData($dictName, $user),
            self::DATA_WRITE => $this->canWriteData($dictName, $user),
            self::DATA_CLEAR => $this->canClearData($dictName, $user),
            self::DATA_CLEAR_DASHBOARD => $this->canClearDataDashboard($dictName, $user),
            self::DATA_NONE => false,
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadDictionaries(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::DICTIONARIES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::DICTIONARIES_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have dictionary.read permission");
            return false;
        }
    }

    private function canWriteDictionaries(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::DICTIONARIES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::DICTIONARIES_WRITE))) {
            return true;
        } else {
            $this->logger->debug("User {$user->getUsername()} does not have dictionary.write permission");
            return false;
        }
    }

    private function canReadData($dictName, User $user) {
        //if $dictName is empty return false
        if (empty($dictName)) {
            return false;
        }

        // dictionary-level permission check
        $this->logger->debug("Checking {$dictName} for dictionary-level permission data.read");
        $role = $user->getUserRole();
        if ($role) {
            $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
            $hasPermission = $dictionaryPermissions && $dictionaryPermissions->canReadData();
            if ($hasPermission) {
                return true;
            }
        }
        $this->logger->debug("User {$user->getUsername()} does not have data.read permission for {$dictName}");
        return false;
    }

    private function canWriteData($dictName, User $user) {
        //if $dictName is empty return false
        if (empty($dictName)) {
            return false;
        }
        // dictionary-level permission check
        $this->logger->debug("Checking {$dictName} for dictionary-level permission data.write");
        $role = $user->getUserRole();
        if ($role) {
            $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
            $hasPermission = $dictionaryPermissions && $dictionaryPermissions->canWriteData();
            if ($hasPermission) {
                return true;
            }
        }
        $this->logger->debug("User {$user->getUsername()} does not have data.write permission for {$dictName}");
        return false;
    }

    private function canClearData($dictName, User $user) {
        //if $dictName is empty return false
        if (empty($dictName)) {
            return false;
        }

        // dictionary-level permission check
        $this->logger->debug("Checking {$dictName} for dictionary-level permission data.clear");
        $role = $user->getUserRole();
        if ($role) {
            $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
            $hasPermission = $dictionaryPermissions && $dictionaryPermissions->canAPIClearData();
            if ($hasPermission) {
                return true;
            }
        }
        $this->logger->debug("User {$user->getUsername()} does not have data.clear permission for {$dictName}");
        return false;
    }

    private function canClearDataDashboard($dictName, User $user) {
        if (empty($dictName)) {
            return false;
        }

        // dictionary-level permission check
        $this->logger->debug("Checking {$dictName} for dictionary-level permission data.clear.dashboard");
        $role = $user->getUserRole();
        if ($role) {
            $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
            $hasPermission = $dictionaryPermissions && $dictionaryPermissions->canClearDashboard();
            if ($hasPermission) {
                return true;
            }
        }
        $this->logger->debug("User {$user->getUsername()} does not have data.clear.dashboard permission for {$dictName}");
        return false;
    }
}
