<?php

namespace AppBundle\Security;

use AppBundle\CSPro\User\User;
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

    public const APPS_ALL = 'apps_all';

    public function __construct(Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::APPS_ALL])) {
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
            self::APPS_ALL => $this->hasAppsRole($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    //built-in administrators and standard users can. For other users with any other role check permissions
    private function hasAppsRole(User $user, $attribute) {
        $roleName = 'ROLE_' . strtoupper($attribute);
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_STANDARD_USER') || $this->security->isGranted($roleName)) {
            return true;
        } else {
            $this->logger->debug('User does not have apps_all permissions');
            return false;
        }
    }

}
