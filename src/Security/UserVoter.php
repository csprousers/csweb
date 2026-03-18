<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of UserVoter
 *
 * @author savy
 */
class UserVoter extends Voter {

    public const USERS_ALL = 'users';
    public const USERS_READ = 'users.read';
    public const USERS_WRITE = 'users.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::USERS_ALL, self::USERS_READ, self::USERS_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('user voter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::USERS_READ => $this->canReadUsers($user, $attribute),
            self::USERS_WRITE => $this->canWriteUsers($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadUsers(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::USERS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::USERS_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have users.read permission");
            return false;
        }
    }

    private function canWriteUsers(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::USERS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::USERS_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have users.write permission");
            return false;
        }
    }
}
