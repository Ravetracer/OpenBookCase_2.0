<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Caretaker;
use App\Entity\OpeningTime;
use App\Entity\WishlistItem;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\CaretakerFactory;
use App\Tests\Factory\OpeningTimeFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Over-long values on the /api/v1 sub-resources (wishlist, caretakers, opening
 * times) are rejected with a 422 carrying `violations` — nothing is written.
 */
final class ApiV1SubResourceValidationTest extends OAuthApiTestCase
{
    public function testWishlistRejectsOverlongTitle(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['wishlist.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/wishlist', $token, ['title' => str_repeat('W', 256)]);

        $this->assertViolation('title');
        $this->assertSame(0, $this->em()->getRepository(WishlistItem::class)->count([]));
    }

    public function testCaretakerCreateRejectsOverlongAddress(): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/caretakers', $token, ['name' => 'Jane', 'address' => ['city' => str_repeat('c', 256)]]);

        $this->assertViolation('address.city');
        $this->assertSame(0, $this->em()->getRepository(Caretaker::class)->count([]));
    }

    public function testCaretakerPatchRejectsOverlongName(): void
    {
        $bc = BookcaseFactory::createOne();
        $caretaker = CaretakerFactory::createOne(['name' => 'Old']);
        $bc->addCaretaker($caretaker);
        $this->em()->flush();
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id . '/caretakers/' . $caretaker->id, $token, ['name' => str_repeat('n', 256)]);

        $this->assertViolation('name');
        $this->em()->clear();
        $this->assertSame('Old', $this->em()->getRepository(Caretaker::class)->find($caretaker->id)?->name);
    }

    public function testOpeningTimeCreateAndPatchRejectOverlongText(): void
    {
        $bc = BookcaseFactory::createOne();
        $existing = OpeningTimeFactory::createOne(['bookcase' => $bc, 'open_time' => 'Sa']);
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $url = '/api/v1/bookcases/' . $bc->id . '/opening-times';

        $this->api('POST', $url, $token, ['openTime' => str_repeat('x', 256)]);
        $this->assertViolation('open_time');

        $this->api('PATCH', $url . '/' . $existing->id, $token, ['openTime' => str_repeat('x', 256)]);
        $this->assertViolation('open_time');

        $this->em()->clear();
        $rows = $this->em()->getRepository(OpeningTime::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('Sa', $rows[0]->open_time);
    }

    private function assertViolation(string $field): void
    {
        $this->assertSame(422, $this->statusCode());
        $this->assertContains($field, array_column($this->json()['violations'] ?? [], 'field'));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
