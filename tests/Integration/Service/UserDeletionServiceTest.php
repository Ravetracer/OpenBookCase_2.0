<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Image;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Service\UserDeletionService;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserDeletionServiceTest extends KernelTestCase
{
    private function service(): UserDeletionService
    {
        return self::getContainer()->get(UserDeletionService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDeleteRemovesUserAndPersonalDataButKeepsImages(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->id;

        $rating = RatingFactory::createOne(['user' => $user]);
        $wish = WishlistItemFactory::createOne(['user' => $user]);
        $image = ImageFactory::createOne(['uploadedBy' => $user]);
        $imageId = $image->id;

        $deleted = $this->service()->deleteUser($userId);
        $this->assertTrue($deleted);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(User::class)->find($userId), 'user must be gone');
        $this->assertNull($this->em()->getRepository(Rating::class)->find($rating->id), 'rating must be deleted');
        $this->assertNull($this->em()->getRepository(WishlistItem::class)->find($wish->id), 'wish must be deleted');

        // The uploaded image survives, with its personal link severed.
        $survivor = $this->em()->getRepository(Image::class)->find($imageId);
        $this->assertNotNull($survivor, 'image must be kept');
        $this->assertNull($survivor->uploadedBy, 'image owner must be nulled');
    }

    public function testDeleteUnknownUserReturnsFalse(): void
    {
        $this->assertFalse($this->service()->deleteUser(new \Symfony\Component\Uid\Ulid()));
    }
}
