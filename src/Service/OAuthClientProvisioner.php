<?php

namespace App\Service;

use App\Entity\ApiApplication;
use App\Enums\ApiClientType;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;

/**
 * Turns an approved ApiApplication into a real OAuth2 client (and tears it down on
 * revoke). Bridges our domain entity to league/oauth2-server-bundle's client store.
 *
 * Clients use the Authorization Code grant (+ refresh). Confidential clients get a
 * one-time secret (returned by provision(); the caller shows it to the applicant
 * exactly once). Public clients have no secret and rely on PKCE.
 */
class OAuthClientProvisioner
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        private readonly CredentialsRevokerInterface $credentialsRevoker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create + persist the OAuth client for an approved application.
     *
     * @return string|null the raw client secret for a confidential client (show once), or null for a public client
     */
    public function provision(ApiApplication $application): ?string
    {
        $identifier = 'obc_' . bin2hex(random_bytes(16));
        $secret = $application->clientType->usesSecret() ? bin2hex(random_bytes(24)) : null;

        $client = new Client($application->appName ?? 'OpenBookCase client', $identifier, $secret);
        $client->setActive(true);
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setRedirectUris(...array_map(
            static fn (string $uri) => new RedirectUri($uri),
            $application->redirectUris,
        ));
        $client->setScopes(...array_map(
            static fn (string $scope) => new Scope($scope),
            $application->requestedScopes,
        ));

        $this->clientManager->save($client);

        $application->oauthClientId = $identifier;
        $application->oauthPlainSecret = $secret; // shown once, then acknowledged away
        $this->entityManager->flush();

        return $secret;
    }

    /**
     * Disable the client and revoke all its issued tokens — instantly killing access.
     */
    public function revoke(ApiApplication $application): void
    {
        $client = $this->findClient($application);
        if ($client === null) {
            return;
        }

        $client->setActive(false);
        $this->clientManager->save($client);
        $this->credentialsRevoker->revokeCredentialsForClient($client);

        $application->oauthPlainSecret = null;
        $this->entityManager->flush();
    }

    private function findClient(ApiApplication $application): ?AbstractClient
    {
        if ($application->oauthClientId === null) {
            return null;
        }

        $client = $this->clientManager->find($application->oauthClientId);

        return $client instanceof AbstractClient ? $client : null;
    }
}
