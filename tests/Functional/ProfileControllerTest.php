<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Image;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ProfileControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Render the homepage (where the profile modal is inlined for logged-in users)
     * and read the named CSRF token straight out of the DOM, so it matches the
     * session the next request will carry. Token ids map to specific hidden inputs.
     */
    private function csrf(string $id): string
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $selectors = [
            'profile_email' => '#profileModal form[data-action*="updateEmail"] input[name="_token"]',
            'profile_home' => '#profileModal form[data-action*="updateHome"] input[name="_token"]',
            'profile_delete' => '#profileModal input[data-profile-target="deleteToken"]',
        ];

        $node = $crawler->filter($selectors[$id]);
        $this->assertGreaterThan(0, $node->count(), "CSRF token input for '$id' not found in DOM");

        return $node->attr('value');
    }

    // ---------------------------------------------------------------------
    // POST /profile/email
    // ---------------------------------------------------------------------

    public function testEmailRequiresAuth(): void
    {
        $this->client->request('POST', '/profile/email', ['email' => 'x@example.com']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testEmailUpdateValid(): void
    {
        $user = $this->loginAsUser(['email' => 'old@example.com']);
        $id = (string) $user->id;

        $this->client->request('POST', '/profile/email', [
            '_token' => $this->csrf('profile_email'),
            'email' => 'new@example.com',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status']);
        $this->assertSame('new@example.com', $this->json()['email']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($id);
        $this->assertSame('new@example.com', $reloaded->email);
    }

    public function testEmailUpdateInvalidCsrfRejected(): void
    {
        $user = $this->loginAsUser(['email' => 'old@example.com']);
        $id = (string) $user->id;

        $this->client->request('POST', '/profile/email', [
            '_token' => 'bogus',
            'email' => 'new@example.com',
        ]);
        $this->assertResponseStatusCodeSame(400);

        $this->em()->clear();
        $this->assertSame('old@example.com', $this->em()->getRepository(User::class)->find($id)->email);
    }

    public function testEmailUpdateInvalidEmailRejected(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/profile/email', [
            '_token' => $this->csrf('profile_email'),
            'email' => 'not-an-email',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    // ---------------------------------------------------------------------
    // POST /profile/home
    // ---------------------------------------------------------------------

    public function testHomeRequiresAuth(): void
    {
        $this->client->request('POST', '/profile/home', ['latitude' => 50, 'longitude' => 10]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testHomeSetEnabled(): void
    {
        $user = $this->loginAsUser();
        $id = (string) $user->id;

        $this->client->request('POST', '/profile/home', [
            '_token' => $this->csrf('profile_home'),
            'enabled' => 1,
            'latitude' => 48.137,
            'longitude' => 11.575,
            'zoom' => 15,
            'label' => 'Home',
        ]);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertTrue($data['enabled']);
        $this->assertSame(48.137, $data['latitude']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($id);
        $this->assertEqualsWithDelta(48.137, $reloaded->homeLatitude, 0.0001);
        $this->assertEqualsWithDelta(11.575, $reloaded->homeLongitude, 0.0001);
        $this->assertSame(15, $reloaded->homeZoom);
        $this->assertTrue($reloaded->useHomeLocation);
        $this->assertSame('Home', $reloaded->homeLabel);
    }

    public function testHomeInvalidPositionRejected(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/profile/home', [
            '_token' => $this->csrf('profile_home'),
            'enabled' => 1,
            'latitude' => 999,
            'longitude' => 11,
            'zoom' => 15,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testHomeClearNullsValues(): void
    {
        $user = $this->loginAsUser([
            'homeLatitude' => 48.0,
            'homeLongitude' => 11.0,
            'homeZoom' => 13,
            'useHomeLocation' => true,
        ]);
        $id = (string) $user->id;

        $this->client->request('POST', '/profile/home', [
            '_token' => $this->csrf('profile_home'),
            'clear' => 1,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['cleared']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($id);
        $this->assertNull($reloaded->homeLatitude);
        $this->assertNull($reloaded->homeLongitude);
        $this->assertNull($reloaded->homeZoom);
        $this->assertFalse($reloaded->useHomeLocation);
    }

    public function testHomeInvalidCsrfRejected(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/profile/home', [
            '_token' => 'bogus',
            'clear' => 1,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    // ---------------------------------------------------------------------
    // POST /profile/delete
    // ---------------------------------------------------------------------

    public function testDeleteRequiresAuth(): void
    {
        $this->client->request('POST', '/profile/delete');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeleteInvalidCsrfRejected(): void
    {
        $user = $this->loginAsUser();
        $id = (string) $user->id;
        $this->client->request('POST', '/profile/delete', ['_token' => 'bogus']);
        $this->assertResponseStatusCodeSame(400);

        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(User::class)->find($id));
    }

    public function testDeleteRemovesAccountKeepsImages(): void
    {
        $user = $this->loginAsUser();
        $id = (string) $user->id;

        $bookcase = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bookcase, 'uploadedBy' => $user]);
        $imageId = (string) $image->id;
        $rating = RatingFactory::createOne(['bookcase' => $bookcase, 'user' => $user]);
        $ratingId = (string) $rating->id;
        $wish = WishlistItemFactory::new()->open()->create(['bookcase' => $bookcase, 'user' => $user]);
        $wishId = (string) $wish->id;

        $this->client->request('POST', '/profile/delete', ['_token' => $this->csrf('profile_delete')]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status']);

        $this->em()->clear();

        // User gone.
        $this->assertNull($this->em()->getRepository(User::class)->find($id));
        // Ratings + wishlist items gone.
        $this->assertNull($this->em()->getRepository(Rating::class)->find($ratingId));
        $this->assertNull($this->em()->getRepository(WishlistItem::class)->find($wishId));
        // Image survives with uploadedBy nulled.
        $reloadedImage = $this->em()->getRepository(Image::class)->find($imageId);
        $this->assertNotNull($reloadedImage, 'uploaded image should survive account deletion');
        $this->assertNull($reloadedImage->uploadedBy, 'uploadedBy must be nulled');
    }
}
