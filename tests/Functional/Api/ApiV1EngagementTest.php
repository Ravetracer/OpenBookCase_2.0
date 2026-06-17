<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Image;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Entity\WishlistItem;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ApiV1EngagementTest extends OAuthApiTestCase
{
    /** @var string[] */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testWishlistReadOpenAndAddScoped(): void
    {
        $bc = BookcaseFactory::createOne();

        // Open read.
        $this->api('GET', '/api/v1/bookcases/' . $bc->id . '/wishlist');
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->json()['items']);

        // Add as the token user.
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['wishlist.write']);
        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/wishlist', $token, ['title' => 'Dune', 'author' => 'Herbert']);
        $this->assertResponseStatusCodeSame(201);

        $this->em()->clear();
        $item = $this->em()->getRepository(WishlistItem::class)->findOneBy(['title' => 'Dune']);
        $this->assertNotNull($item);
        $this->assertSame((string) $user->id, (string) $item->user->id, 'wish belongs to the token user');
    }

    public function testWatchAddAndRemove(): void
    {
        $user = UserFactory::createOne();
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor($user, ['watchlist.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/watch', $token);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['watching']);
        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(WatchlistItem::class)->findBy(['user' => $user->id]));

        $this->api('DELETE', '/api/v1/bookcases/' . $bc->id . '/watch', $token);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->json()['watching']);
        $this->em()->clear();
        $this->assertCount(0, $this->em()->getRepository(WatchlistItem::class)->findBy(['user' => $user->id]));
    }

    public function testHomeWriteDoesNotToggleCenterSwitch(): void
    {
        $user = UserFactory::createOne(['useHomeLocation' => false]);
        $token = $this->tokenFor($user, ['home.write']);

        $this->api('POST', '/api/v1/profile/home', $token, ['latitude' => 48.13, 'longitude' => 11.57, 'zoom' => 15]);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertEqualsWithDelta(48.13, $reloaded->homeLatitude, 0.0001);
        $this->assertSame(15, $reloaded->homeZoom);
        $this->assertFalse($reloaded->useHomeLocation, 'the "center on home" switch must stay untouched');
    }

    public function testImageUploadRequiresScopeAndAttributesUser(): void
    {
        if (!\function_exists('imagejpeg')) {
            $this->markTestSkipped('GD (imagejpeg) required.');
        }
        $user = UserFactory::createOne();
        $bc = BookcaseFactory::createOne();
        $token = $this->tokenFor($user, ['images.write']);

        $this->client->request(
            'POST',
            '/api/v1/bookcases/' . $bc->id . '/images',
            ['author' => 'Jane Doe'],
            ['imageFile' => $this->makeJpeg()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $this->assertResponseStatusCodeSame(201);

        $this->em()->clear();
        $image = $this->em()->getRepository(Image::class)->findOneBy([]);
        $this->assertNotNull($image);
        $this->cleanup[] = static::getContainer()->getParameter('kernel.project_dir') . '/public/images/' . $image->filename;
        $this->assertSame((string) $user->id, (string) $image->uploadedBy->id, 'image attributed to the token user');
    }

    public function testImageUploadWithoutTokenIsUnauthorized(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/v1/bookcases/' . $bc->id . '/images', ['author' => 'X']);
        $this->assertSame(401, $this->statusCode());
    }

    private function makeJpeg(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'obc_api_') . '.jpg';
        $im = imagecreatetruecolor(10, 10);
        imagejpeg($im, $tmp);
        imagedestroy($im);
        $this->cleanup[] = $tmp;

        return new UploadedFile($tmp, 'test.jpg', 'image/jpeg', null, true);
    }
}
