<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Shared base for functional (HTTP) tests. Provides a booted client and login
 * helpers. The DB is reset by Foundry and wrapped in a DAMA transaction per
 * test (see phpunit.xml.dist), so every test starts from an empty database.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The API rate limiter persists its state in a filesystem cache pool (so it
        // survives the kernel reboot the client does between requests). Clear it per
        // test so limiter counts never leak across tests or earlier suite runs.
        $pool = static::getContainer()->get('rate_limiter.cache');
        if ($pool instanceof \Psr\Cache\CacheItemPoolInterface) {
            $pool->clear();
        }
    }

    /** Create a verified user and authenticate the client as them. */
    protected function loginAsUser(array $attributes = []): User
    {
        $user = UserFactory::createOne($attributes);
        $this->client->loginUser($user);

        return $user;
    }

    /** Decode the JSON body of the last response. */
    protected function json(): array
    {
        return json_decode($this->client->getResponse()->getContent() ?: '[]', true) ?? [];
    }
}
