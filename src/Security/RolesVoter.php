<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of RolesVoter
 *
 * @author savy
 */
class RolesVoter extends Voter {

    public const ROLES_ALL = 'roles';
    public const ROLES_READ = 'roles.read';
    public const ROLES_WRITE = 'roles.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::ROLES_ALL, self::ROLES_READ, self::ROLES_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('RolesVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::ROLES_READ => $this->canReadRoles($user, $attribute),
            self::ROLES_WRITE => $this->canWriteRoles($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadRoles(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::ROLES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::ROLES_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have roles.read permission");
            return false;
        }
    }

    private function canWriteRoles(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::ROLES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::ROLES_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have roles.write permission");
            return false;
        }
    }
}
