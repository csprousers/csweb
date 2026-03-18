<?php

namespace App\Security;

use App\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of ReportsVoter
 *
 * @author savy
 */
class ReportsVoter extends Voter {

    public const REPORTS_ALL = 'reports';
    public const REPORTS_READ = 'reports.read';
    public const REPORTS_WRITE = 'reports.write';

    public function __construct(private Security $security, private LoggerInterface $logger) {
        $this->security = $security;
    }

    protected function supports($attribute, $subject) : bool {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::REPORTS_ALL, self::REPORTS_READ, self::REPORTS_WRITE])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) : bool {
        $user = $token->getUser();
        $this->logger->debug('ReportsVoter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        return match ($attribute) {
            self::REPORTS_READ => $this->canReadReports($user, $attribute),
            self::REPORTS_WRITE => $this->canWriteReports($user, $attribute),
            default => throw new \LogicException('This code should not be reached!'),
        };
    }

    private function canReadReports(User $user, $attribute) {

        if ($this->security->isGranted('ROLE_' . strtoupper(self::REPORTS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::REPORTS_READ))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have reports.read permission");
            return false;
        }
    }

    private function canWriteReports(User $user, $attribute) {
        if ($this->security->isGranted('ROLE_' . strtoupper(self::REPORTS_ALL)) || $this->security->isGranted('ROLE_' . strtoupper(self::REPORTS_WRITE))) {
            return true;
        } else {
            $username = $user->getUsername();
            $this->logger->debug("User {$username} does not have reports.write permission");
            return false;
        }
    }
}
