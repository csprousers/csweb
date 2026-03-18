<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of MessagesVoter
 *
 * @author savy
 */
class MessagesVoter extends Voter {

    public const MESSAGES_ALL = 'messages';
    public const MESSAGES_READ = 'messages.read';
    public const MESSAGES_WRITE = 'messages.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::MESSAGES_ALL, self::MESSAGES_READ, self::MESSAGES_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('MessagesVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::MESSAGES_READ => $this->canReadMessages($user, $attribute),
            self::MESSAGES_WRITE => $this->canWriteMessages($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadMessages(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::MESSAGES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::MESSAGES_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have messages.read permission");
            return false;
        }
    }

    private function canWriteMessages(User $user, $attribute) {
         if ($this->security->isGranted('ROLE_' . strtoupper(self::MESSAGES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::MESSAGES_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have messages.write permission");
            return false;
        }
    }
}
