<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Enums\ApiClientType;
use App\Service\OAuthClientProvisioner;
use App\Tests\Factory\ApiApplicationFactory;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OAuthClientProvisionerTest extends KernelTestCase
{
    private function provisioner(): OAuthClientProvisioner
    {
        return self::getContainer()->get(OAuthClientProvisioner::class);
    }

    private function clients(): ClientManagerInterface
    {
        return self::getContainer()->get(ClientManagerInterface::class);
    }

    public function testProvisionConfidentialClientReturnsSecretAndStoresIt(): void
    {
        $app = ApiApplicationFactory::createOne([
            'clientType' => ApiClientType::Confidential,
            'redirectUris' => ['https://example.com/cb'],
            'requestedScopes' => ['bookcases.write', 'images.write'],
        ]);

        $secret = $this->provisioner()->provision($app);

        $this->assertNotNull($secret);
        $this->assertNotEmpty($app->oauthClientId);
        $this->assertSame($secret, $app->oauthPlainSecret, 'raw secret is held once on the application');

        $client = $this->clients()->find($app->oauthClientId);
        $this->assertNotNull($client);
        $this->assertTrue($client->isActive());
        $this->assertTrue($client->isConfidential());
        $grants = array_map('strval', $client->getGrants());
        $this->assertContains('authorization_code', $grants);
        $this->assertContains('refresh_token', $grants);
        $scopes = array_map('strval', $client->getScopes());
        $this->assertSame(['bookcases.write', 'images.write'], $scopes);
    }

    public function testProvisionPublicClientHasNoSecret(): void
    {
        $app = ApiApplicationFactory::createOne(['clientType' => ApiClientType::PublicClient]);

        $secret = $this->provisioner()->provision($app);

        $this->assertNull($secret);
        $this->assertNull($app->oauthPlainSecret);
        $client = $this->clients()->find($app->oauthClientId);
        $this->assertFalse($client->isConfidential());
    }

    public function testRevokeDisablesClientAndClearsSecret(): void
    {
        $app = ApiApplicationFactory::createOne(['clientType' => ApiClientType::Confidential]);
        $this->provisioner()->provision($app);
        $this->assertTrue($this->clients()->find($app->oauthClientId)->isActive());

        $this->provisioner()->revoke($app);

        $this->assertFalse($this->clients()->find($app->oauthClientId)->isActive());
        $this->assertNull($app->oauthPlainSecret);
    }
}
