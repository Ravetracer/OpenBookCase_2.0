<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ApiApplication;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The apply form must refuse redirect URIs whose scheme would turn the OAuth
 * callback into script execution or local-file access.
 */
final class ApiApplicationRedirectUriTest extends FunctionalTestCase
{
    public function testApplyWithJavascriptRedirectUriIsRejected(): void
    {
        UserFactory::new()->admin()->create();
        $this->loginAsUser();

        $this->client->request('POST', '/profile/api/apply', [
            '_token' => $this->applyToken(),
            'appName' => 'Sneaky App',
            'useCase' => 'A mobile app that shows nearby bookcases to my users.',
            'clientType' => 'public',
            'scopes' => ['bookcases.write'],
            'redirectUris' => "com.example.app:/callback\njavascript:alert(document.cookie)",
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $this->json());
        $this->assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(ApiApplication::class)->count([]));
    }

    private function applyToken(): string
    {
        $crawler = $this->client->request('GET', '/');

        return (string) $crawler->filter('#profileModal form[data-action*="applyApi"] input[name="_token"]')->attr('value');
    }
}
