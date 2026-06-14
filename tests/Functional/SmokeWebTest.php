<?php declare(strict_types=1);

namespace App\Tests\Functional;

final class SmokeWebTest extends FunctionalTestCase
{
    public function testHomepageRenders(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }

    public function testApiBoundingBoxRequiresParams(): void
    {
        // Entirely missing bbox params → 400 (documented behaviour).
        $this->client->request('GET', '/api/bookcase/');
        $this->assertResponseStatusCodeSame(400);
    }
}
