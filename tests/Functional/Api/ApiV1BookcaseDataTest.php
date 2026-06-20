<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Caretaker;
use App\Entity\OpeningTime;
use App\Entity\Rating;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\CaretakerFactory;
use App\Tests\Factory\OpeningTimeFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * /api/v1 write endpoints for the data that belongs to a bookcase entry but lives
 * in related tables: caretakers, opening times and (per-user) ratings.
 */
final class ApiV1BookcaseDataTest extends OAuthApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // ── Caretakers ─────────────────────────────────────────────────────────

    public function testCaretakerCrud(): void
    {
        $bc = BookcaseFactory::createOne();
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['bookcases.write']);

        // Read is open.
        $this->api('GET', '/api/v1/bookcases/' . $bc->id . '/caretakers');
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->json()['caretakers']);

        // Create + attach.
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/caretakers', $token, [
            'name' => 'Jane Keeper',
            'contact' => 'jane@example.com',
            'address' => ['street' => 'Main St', 'city' => 'Berlin'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $created = $this->json();
        $this->assertSame('Jane Keeper', $created['name']);
        $this->assertSame('Berlin', $created['address']['city']);
        $id = $created['id'];

        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(Caretaker::class)->findBy(['name' => 'Jane Keeper']));

        // Update.
        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id . '/caretakers/' . $id, $token, ['contact' => 'new@example.com']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('new@example.com', $this->json()['contact']);
        $this->assertSame('Jane Keeper', $this->json()['name'], 'omitted fields are left untouched');

        // Delete (only bookcase → caretaker is removed entirely).
        $this->api('DELETE', '/api/v1/bookcases/' . $bc->id . '/caretakers/' . $id, $token);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Caretaker::class)->find($id));
    }

    public function testCaretakerCreateRequiresScope(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/caretakers', null, ['name' => 'X']);
        $this->assertSame(401, $this->statusCode());

        $token = $this->tokenFor(UserFactory::createOne(), ['wishlist.write']);
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/caretakers', $token, ['name' => 'X']);
        $this->assertSame(403, $this->statusCode(), 'wrong scope is forbidden');
    }

    public function testCaretakerCreateRejectsEmpty(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/caretakers', $token, ['name' => '', 'contact' => '']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCaretakerDeleteKeepsSharedCaretaker(): void
    {
        $caretaker = CaretakerFactory::createOne(['name' => 'Shared']);
        $a = BookcaseFactory::createOne();
        $b = BookcaseFactory::createOne();
        $a->addCaretaker($caretaker);
        $b->addCaretaker($caretaker);
        $this->em()->flush();

        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $this->api('DELETE', '/api/v1/bookcases/' . $a->id . '/caretakers/' . $caretaker->id, $token);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertNotNull(
            $this->em()->getRepository(Caretaker::class)->find($caretaker->id),
            'a caretaker still attached to another bookcase is kept',
        );
    }

    // ── Opening times ──────────────────────────────────────────────────────

    public function testOpeningTimeCrud(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('GET', '/api/v1/bookcases/' . $bc->id . '/opening-times');
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->json()['openingTimes']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/opening-times', $token, ['openTime' => 'Mo-Fr 08:00-20:00']);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        $this->assertSame('Mo-Fr 08:00-20:00', $this->json()['openTime']);
        $this->assertFalse($this->json()['twentyFourSeven']);

        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id . '/opening-times/' . $id, $token, ['twentyFourSeven' => true]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['twentyFourSeven']);

        $this->api('DELETE', '/api/v1/bookcases/' . $bc->id . '/opening-times/' . $id, $token);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(OpeningTime::class)->find($id));
    }

    public function testOpeningTimeTwentyFourSevenWithoutText(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/opening-times', $token, ['twentyFourSeven' => true]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testOpeningTimeRejectsEmpty(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/opening-times', $token, []);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testOpeningTimeOfAnotherBookcaseIsNotFound(): void
    {
        $a = BookcaseFactory::createOne();
        $b = BookcaseFactory::createOne();
        $ot = OpeningTimeFactory::createOne(['bookcase' => $b]);
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('PATCH', '/api/v1/bookcases/' . $a->id . '/opening-times/' . $ot->id, $token, ['openTime' => 'x']);
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Ratings ────────────────────────────────────────────────────────────

    public function testRatingUpsertAndDelete(): void
    {
        $bc = BookcaseFactory::createOne();
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['ratings.write']);

        // Aggregate read is open and empty initially.
        $this->api('GET', '/api/v1/bookcases/' . $bc->id . '/rating');
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['count']);

        // First vote.
        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', $token, ['value' => 4]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(4, $this->json()['userValue']);
        $this->assertSame(1, $this->json()['count']);
        $this->assertEqualsWithDelta(4.0, $this->json()['average'], 0.001);

        // Re-rate (upsert, not a second row).
        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', $token, ['value' => 2]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->json()['count']);
        $this->assertSame(2, $this->json()['userValue']);

        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(Rating::class)->findBy(['user' => $user->id]));

        // Delete.
        $this->api('DELETE', '/api/v1/bookcases/' . $bc->id . '/rating', $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['count']);
        $this->em()->clear();
        $this->assertCount(0, $this->em()->getRepository(Rating::class)->findBy(['user' => $user->id]));
    }

    public function testRatingAverageAcrossUsers(): void
    {
        $bc = BookcaseFactory::createOne();
        RatingFactory::createOne(['bookcase' => $bc, 'value' => 5]);
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['ratings.write']);

        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', $token, ['value' => 1]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $this->json()['count']);
        $this->assertEqualsWithDelta(3.0, $this->json()['average'], 0.001);
    }

    public function testRatingRejectsOutOfRange(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['ratings.write']);
        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', $token, ['value' => 9]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRatingRequiresScope(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', null, ['value' => 3]);
        $this->assertSame(401, $this->statusCode());

        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $this->api('PUT', '/api/v1/bookcases/' . $bc->id . '/rating', $token, ['value' => 3]);
        $this->assertSame(403, $this->statusCode(), 'ratings need ratings.write, not bookcases.write');
    }
}
