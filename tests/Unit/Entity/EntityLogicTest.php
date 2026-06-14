<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Image;
use App\Entity\Message;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class EntityLogicTest extends TestCase
{
    public function testUserAlwaysHasRoleUser(): void
    {
        $user = new User();
        $this->assertSame(['ROLE_USER'], $user->getRoles());

        $user->roles = ['ROLE_ADMIN'];
        $roles = $user->getRoles();
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }

    public function testUserRolesAreDeduplicated(): void
    {
        $user = new User();
        $user->roles = ['ROLE_USER', 'ROLE_USER'];
        $this->assertSame(['ROLE_USER'], array_values($user->getRoles()));
    }

    public function testUserIdentifierIsUsername(): void
    {
        $user = new User();
        $user->username = 'alice';
        $this->assertSame('alice', $user->getUserIdentifier());
    }

    public function testImageUniqueFileNameIncludesId(): void
    {
        $image = new Image();
        $image->id = new Ulid();
        $name = $image->getUniqueFileName();

        $this->assertStringStartsWith('bookcase_' . $image->id . '_', $name);
    }

    public function testImageSetImageFileTouchesUpdatedAt(): void
    {
        $image = new Image();
        $this->assertNull($image->updatedAt);

        $image->setImageFile(new \Symfony\Component\HttpFoundation\File\File(__FILE__));
        $this->assertInstanceOf(\DateTimeInterface::class, $image->updatedAt);
    }

    public function testMessageIsReadReflectsReadAt(): void
    {
        $message = new Message();
        $this->assertFalse($message->isRead());

        $message->readAt = new \DateTimeImmutable();
        $this->assertTrue($message->isRead());
    }
}
