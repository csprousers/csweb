<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of SettingsVoter
 *
 * @author savy
 */
class SettingsVoter extends Voter {

    public const SETTINGS_ALL = 'settings';
    public const SETTINGS_READ = 'settings.read';
    public const SETTINGS_WRITE = 'settings.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::SETTINGS_ALL, self::SETTINGS_READ, self::SETTINGS_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('SettingsVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::SETTINGS_READ => $this->canReadSettings($user, $attribute),
            self::SETTINGS_WRITE => $this->canWriteSettings($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadSettings(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::SETTINGS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::SETTINGS_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have settings.read permission");
            return false;
        }
    }

    private function canWriteSettings(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::SETTINGS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::SETTINGS_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have settings.write permission");
            return false;
        }
    }
}
