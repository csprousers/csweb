<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of FilesVoter
 *
 * @author savy
 */
class FilesVoter extends Voter {

    public const FILES_ALL = 'files';
    public const FILES_READ = 'files.read';
    public const FILES_WRITE = 'files.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::FILES_ALL, self::FILES_READ, self::FILES_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('FilesVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::FILES_READ => $this->canReadFiles($user, $attribute),
            self::FILES_WRITE => $this->canWriteFiles($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadFiles(User $user, $attribute) {

        if ($this->security->isGranted('ROLE_' . strtoupper(self::FILES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::FILES_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have files.read permission");
            return false;
        }
    }

    private function canWriteFiles(User $user, $attribute) {
         if ($this->security->isGranted('ROLE_' . strtoupper(self::FILES_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::FILES_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have files.write permission");
            return false;
        }
    }
}
