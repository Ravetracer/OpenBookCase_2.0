<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ApiV1BookcaseTest extends OAuthApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // ── Open reads (no token) ─────────────────────────────────────────────

    public function testBboxReadIsOpen(): void
    {
        BookcaseFactory::new()->at(52.5, 13.4)->create(['title' => 'In Box']);
        BookcaseFactory::new()->at(0.0, 0.0)->create(['title' => 'Far Away']);

        $this->api('GET', '/api/v1/bookcases?latMin=52&latMax=53&lonMin=13&lonMax=14');
        $this->assertResponseIsSuccessful();
        $titles = array_column($this->json()['markers'], 'title');
        $this->assertContains('In Box', $titles);
        $this->assertNotContains('Far Away', $titles);
    }

    public function testSingleReadIsOpen(): void
    {
        $bc = BookcaseFactory::createOne(['title' => 'Readable']);
        $this->api('GET', '/api/v1/bookcases/' . $bc->id);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Readable', $this->json()['title']);
    }

    // ── Auth / scope gating ───────────────────────────────────────────────

    public function testCreateWithoutTokenIsUnauthorized(): void
    {
        $this->api('POST', '/api/v1/bookcases', null, ['title' => 'X', 'latitude' => 1, 'longitude' => 2]);
        $this->assertSame(401, $this->statusCode());
    }

    public function testWrongScopeIsForbidden(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['wishlist.write']);
        $this->api('POST', '/api/v1/bookcases', $token, ['title' => 'X', 'latitude' => 1, 'longitude' => 2]);
        $this->assertSame(403, $this->statusCode());
    }

    // ── Writes (correct scope) ────────────────────────────────────────────

    public function testCreateWithScope(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases', $token, [
            'title' => 'Created via API',
            'entryType' => 'givebox',
            'latitude' => 52.52,
            'longitude' => 13.4,
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Created via API', $this->json()['title']);

        $this->em()->clear();
        $found = $this->em()->getRepository(Bookcase::class)->findOneBy(['title' => 'Created via API']);
        $this->assertNotNull($found);
        $this->assertNotNull($found->shortCode, 'a short code is assigned on create');
    }

    public function testCreateRejectsUrlInTitle(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $this->api('POST', '/api/v1/bookcases', $token, [
            'title' => 'Visit https://spam.example',
            'latitude' => 1,
            'longitude' => 2,
        ]);
        $this->assertSame(422, $this->statusCode());
    }

    public function testUpdateTitle(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $bc = BookcaseFactory::createOne(['title' => 'Before']);

        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id, $token, ['title' => 'After']);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertSame('After', $this->em()->getRepository(Bookcase::class)->find($bc->id)->title);
    }

    public function testUpdateTitleClearsProvisionalFlag(): void
    {
        // Regression: an OSM-imported entry (provisional title) edited via the API
        // must drop the "help name this bookcase" prompt once a real title is set.
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $bc = BookcaseFactory::createOne(['title' => 'Public bookcase', 'titleProvisional' => true]);

        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id, $token, ['title' => 'Named by user']);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertFalse($this->em()->getRepository(Bookcase::class)->find($bc->id)->titleProvisional);
    }

    public function testPositionUpdate(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $bc = BookcaseFactory::new()->at(10.0, 10.0)->create();

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/position', $token, ['latitude' => 48.1, 'longitude' => 11.6]);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $moved = $this->em()->getRepository(Bookcase::class)->find($bc->id);
        $this->assertEqualsWithDelta(48.1, $moved->position->latitude, 0.0001);
    }

    public function testDeleteArchivesSnapshot(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.delete']);
        $bc = BookcaseFactory::createOne(['title' => 'Doomed']);
        $id = (string) $bc->id;

        // Reason is mandatory.
        $this->api('DELETE', '/api/v1/bookcases/' . $id, $token, []);
        $this->assertSame(422, $this->statusCode());

        $this->api('DELETE', '/api/v1/bookcases/' . $id, $token, ['reason' => 'duplicate entry']);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Bookcase::class)->find($id));
        $archived = $this->em()->getRepository(DeletedBookcase::class)->findOneBy(['originalId' => $id]);
        $this->assertNotNull($archived);
        $this->assertSame('duplicate entry', $archived->reason);
    }
}
