<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of LoginVoter
 *
 * @author alw
 */
class LoginVoter extends Voter {

    public const LOGIN_ALL = 'login'; //access to dashboard

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::LOGIN_ALL])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('Login voter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::LOGIN_ALL => $this->canLogin($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canLogin(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::LOGIN_ALL))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have login permission");
            return false;
        }
    }
}
