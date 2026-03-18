<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of ParadataVoter
 *
 * @author savy
 */
class ParadataVoter extends Voter {

    public const PARADATA_ALL = 'paradata';
    public const PARADATA_READ = 'paradata.read';
    public const PARADATA_WRITE = 'paradata.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::PARADATA_ALL, self::PARADATA_READ, self::PARADATA_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('ParadataVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::PARADATA_READ => $this->canReadParadata($user, $attribute),
            self::PARADATA_WRITE => $this->canWriteParadata($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadParadata(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::PARADATA_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::PARADATA_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have paradata.read permission");
            return false;
        }
    }

    private function canWriteParadata(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::PARADATA_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::PARADATA_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have paradata.write permission");
            return false;
        }
    }
}
