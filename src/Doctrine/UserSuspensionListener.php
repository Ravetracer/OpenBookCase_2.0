<?php declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\User;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;

/**
 * Suspending an account must also cut off its OAuth2 API access: the refresh
 * grant never consults the user, so without revocation a suspended account could
 * keep minting fresh access tokens. Hooked on the entity (not the admin action)
 * so every way of setting the flag — single toggle, bulk action, console — is covered.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: User::class)]
final class UserSuspensionListener
{
    public function __construct(
        private readonly CredentialsRevokerInterface $credentialsRevoker,
    ) {
    }

    public function preUpdate(User $user, PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('isSuspended') && $args->getNewValue('isSuspended') === true) {
            $this->credentialsRevoker->revokeCredentialsForUser($user);
        }
    }
}
