<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of AppsVoter
 *
 * @author savy
 */
class AppsVoter extends Voter {

    public const APPS_ALL = 'apps';
    public const APPS_READ = 'apps.read';
    public const APPS_WRITE = 'apps.write';
    
    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::APPS_ALL, self::APPS_READ, self::APPS_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('AppsVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::APPS_READ => $this->canReadApps($user, $attribute),
            self::APPS_WRITE => $this->canWriteApps($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadApps(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::APPS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::APPS_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have apps.read permission");
            return false;
        }
    }

    private function canWriteApps(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::APPS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::APPS_WRITE))){
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have apps.write permission");
            return false;
        }
    }
}
