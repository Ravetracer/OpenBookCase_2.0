<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        // A suspended account cannot log in at all, regardless of verification.
        if ($user->isSuspended) {
            throw new CustomUserMessageAccountStatusException('account.suspended');
        }

        // Block login until the account is unlocked via the e-mailed
        // verification link. Legacy users are imported as already verified.
        if (!$user->isVerified) {
            throw new CustomUserMessageAccountStatusException('account.not_verified');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
