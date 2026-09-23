<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ApiApplication;
use App\Entity\ApiUsageLog;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enums\ApiApplicationStatus;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WishlistItemFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stored XSS in content that is not a bookcase field: wishlist items, image
 * credits/alt text, usernames/e-mails/home label, API-access applications and
 * their message threads, and the admin views that display all of it. Each
 * payload is sent through the real write endpoint where one exists, then
 * checked in every view that renders it — including other users' views (the
 * actual stored-XSS victims: admins, requesters, donors).
 */
final class UserContentXssTest extends FunctionalTestCase
{
    use XssAssertions;

    /** @var string[] files written to public/images during a test */
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

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** GET a URL and assert the payload is rendered only as escaped text. */
    private function assertEscapedAt(string $url, string $payload, bool $expectEscapedForm = true): void
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful("GET $url");
        $this->assertPayloadEscaped($this->html(), $payload, $expectEscapedForm);
    }

    /** Read a CSRF token from the (server-rendered) profile modal. */
    private function csrf(string $selector): string
    {
        $crawler = $this->client->request('GET', '/list');
        $node = $crawler->filter($selector);
        $this->assertGreaterThan(0, $node->count(), "token input not found: $selector");

        return (string) $node->attr('value');
    }

    // ── Wishlist ──────────────────────────────────────────────────────────

    public static function wishlistFieldCases(): iterable
    {
        foreach (['title', 'author', 'isbn', 'misc'] as $field) {
            foreach (self::htmlPayloads() as $name => [$payload]) {
                yield "$field / $name" => [$field, $payload];
            }
        }
    }

    #[DataProvider('wishlistFieldCases')]
    public function testWishlistFieldsAreEscaped(string $field, string $payload): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Wish Shelf']);
        $fields = ['title' => 'Dune', $field => $payload];

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist', $fields);
        $this->assertResponseIsSuccessful();

        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/wishlist', $payload);
        if (\in_array($field, ['title', 'author'], true)) {
            // "My wishlist" in the profile (live-refresh endpoint + the modal itself).
            $this->assertEscapedAt('/profile/wishlist', $payload);
            $this->assertEscapedAt('/list', $payload);
        }
    }

    #[DataProvider('htmlPayloads')]
    public function testWishlistDropNotificationEscapesDonorNameAndTitles(string $payload): void
    {
        $requester = UserFactory::createOne();
        $bc = BookcaseFactory::createOne(['title' => $payload]);
        $item = WishlistItemFactory::createOne(['bookcase' => $bc, 'user' => $requester, 'title' => $payload]);

        $this->loginAsUser(['username' => $payload]);
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $item->id . '/status', ['action' => 'drop']);
        $this->assertResponseIsSuccessful();

        // Third parties see "waiting for pickup (dropped by <username>)".
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();
        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/wishlist', $payload);

        // The requester's inbox names the donor, the book and the bookcase.
        $this->client->loginUser($requester);
        $this->assertEscapedAt('/messages', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testWishlistNotFoundCommentIsEscapedInDonorInbox(string $payload): void
    {
        $donor = UserFactory::createOne();
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Missing Book Shelf']);
        $item = WishlistItemFactory::new()->dropped()->create(['bookcase' => $bc, 'user' => $requester, 'droppedBy' => $donor]);

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $item->id . '/status', [
            'action' => 'notfound',
            'comment' => $payload,
        ]);
        $this->assertResponseIsSuccessful();

        $this->client->loginUser($donor);
        $this->assertEscapedAt('/messages', $payload);
    }

    // ── Images: author credit + alt text ──────────────────────────────────

    public static function imageFieldCases(): iterable
    {
        foreach (['author', 'altText'] as $field) {
            foreach (self::htmlPayloads() as $name => [$payload]) {
                yield "$field / $name" => [$field, $payload];
            }
        }
    }

    #[DataProvider('imageFieldCases')]
    public function testSeededImageFieldsAreEscaped(string $field, string $payload): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Photo Shelf']);
        ImageFactory::createOne(['bookcase' => $bc, $field => $payload]);

        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/html', $payload);
        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/photos', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testUploadedImageAuthorAndAltAreEscaped(string $payload): void
    {
        if (!\function_exists('imagejpeg')) {
            $this->markTestSkipped('GD extension (imagejpeg) is required for image upload tests.');
        }
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Upload Shelf']);

        $tmp = tempnam(sys_get_temp_dir(), 'obc_xss_') . '.jpg';
        imagejpeg(imagecreatetruecolor(10, 10), $tmp);
        $this->cleanup[] = $tmp;

        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/image',
            ['author' => $payload, 'altText' => $payload],
            ['imageFile' => new UploadedFile($tmp, 'test.jpg', 'image/jpeg', null, true)],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->cleanup[] = static::getContainer()->getParameter('kernel.project_dir') . '/public/images/' . $this->json()['filename'];

        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/html', $payload);
        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/photos', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testUpdatedAltTextIsEscaped(string $payload): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Alt Shelf']);
        $image = ImageFactory::createOne(['bookcase' => $bc, 'author' => 'Jane']);

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $image->id . '/alt', ['altText' => $payload]);
        $this->assertResponseIsSuccessful();

        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/html', $payload);
        $this->assertEscapedAt('/api/bookcase/' . $bc->id . '/photos', $payload);
    }

    // ── Users: username, e-mail, home label ───────────────────────────────

    #[DataProvider('htmlPayloads')]
    public function testRegisteredUsernameIsEscapedInAdminViews(string $payload): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration_form"]')->form();
        $form['registration_form[username]'] = $payload;
        $form['registration_form[email]'] = 'xss-' . md5($payload) . '@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        $form['registration_form[agreeTerms]']->tick();
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => $payload]);
        $this->assertNotNull($user, 'registration with the payload username did not persist');

        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $this->assertEscapedAt('/admin/users', $payload);
        $this->assertEscapedAt('/admin/users?q=' . rawurlencode($payload), $payload);
        $this->assertEscapedAt('/admin/users/' . $user->id, $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testSeededEmailIsEscapedInAdminAndProfileViews(string $payload): void
    {
        // E-mail validation normally blocks markup; this guards the output side
        // should a malformed address ever reach the DB (legacy import, admin edit).
        $victim = UserFactory::createOne(['email' => $payload]);

        $this->client->loginUser($victim);
        $this->assertEscapedAt('/list', $payload); // profile modal e-mail field

        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $this->assertEscapedAt('/admin/users', $payload);
        $this->assertEscapedAt('/admin/users/' . $victim->id, $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testOwnUsernameIsEscapedInLayout(string $payload): void
    {
        $this->loginAsUser(['username' => $payload]);

        // Navbar, profile modal and the map page's Stimulus values.
        $this->assertEscapedAt('/list', $payload);
        $this->assertEscapedAt('/', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testHomeLabelIsEscapedInMapValuesAndProfile(string $payload): void
    {
        $this->loginAsUser();
        $token = $this->csrf('#profileModal form[data-action*="updateHome"] input[name="_token"]');

        $this->client->request('POST', '/profile/home', [
            '_token' => $token,
            'label' => $payload,
            'enabled' => '1',
            'latitude' => '52.5',
            'longitude' => '13.4',
            'zoom' => '12',
        ]);
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertJsonCarriesPayload((string) $response->getContent(), $response->headers->get('Content-Type'), $payload);

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
        // The label reaches the map as a Stimulus value: intact as data, not markup.
        $this->assertSame($payload, $crawler->filter('[data-map-homecustomlabel-value]')->attr('data-map-homecustomlabel-value'));
    }

    // ── API-access applications ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function applyForm(string $appName, string $useCase, string $redirectUris): array
    {
        return [
            'appName' => $appName,
            'useCase' => $useCase,
            'clientType' => 'confidential',
            'scopes' => ['bookcases.write'],
            'redirectUris' => $redirectUris,
        ];
    }

    #[DataProvider('htmlPayloads')]
    public function testApplicationFieldsAreEscapedForApplicantAndAdmin(string $payload): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->loginAsUser();
        $token = $this->csrf('#profileModal form[data-action*="applyApi"] input[name="_token"]');

        $this->client->request('POST', '/profile/api/apply', ['_token' => $token] + $this->applyForm(
            $payload,
            $payload . ' — a long enough use case description.',
            "https://example.com/cb\n" . $payload,
        ));
        $this->assertResponseStatusCodeSame(201);
        $app = $this->em()->getRepository(ApiApplication::class)->findOneBy([]);
        $this->assertNotNull($app);

        // Applicant's own profile (pending state shows the app name).
        $this->assertEscapedAt('/list', $payload);

        // Admin: list, detail (name, use case, redirect URIs), and the "new application" inbox note.
        $this->client->loginUser($admin);
        $this->assertEscapedAt('/admin/api-applications', $payload);
        $this->assertEscapedAt('/admin/api-applications/' . $app->id, $payload);
        $this->assertEscapedAt('/messages', $payload, expectEscapedForm: false);
    }

    #[DataProvider('dangerousUrls')]
    public function testRedirectUriIsNeverRenderedAsLink(string $url): void
    {
        $admin = UserFactory::new()->admin()->create();
        $app = ApiApplicationFactory::createOne(['redirectUris' => [$url], 'appName' => 'Scheme App']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $this->assertResponseIsSuccessful();
        $this->assertNoLiveXss($this->html());
    }

    #[DataProvider('htmlPayloads')]
    public function testApplicantReplyIsEscapedForAdmin(string $payload): void
    {
        $admin = UserFactory::new()->admin()->create();
        $applicant = $this->loginAsUser();
        $app = ApiApplicationFactory::createOne(['applicant' => $applicant, 'appName' => 'Reply App']);
        $token = $this->csrf('#profileModal form[data-action*="replyApi"] input[name="_token"]');

        $this->client->request('POST', '/profile/api/' . $app->id . '/reply', ['_token' => $token, 'body' => $payload]);
        $this->assertResponseIsSuccessful();

        // Applicant's own thread view.
        $this->assertEscapedAt('/list', $payload);

        $this->client->loginUser($admin);
        $this->assertEscapedAt('/admin/api-applications/' . $app->id, $payload);
        $this->assertEscapedAt('/messages', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testAdminMessageAndDenyReasonAreEscapedForApplicant(string $payload): void
    {
        $applicant = UserFactory::createOne();
        $app = ApiApplicationFactory::createOne(['applicant' => $applicant, 'appName' => 'Decision App']);

        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $crawler = $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $form = $crawler->filter('form[action$="/message"]')->form();
        $form['body'] = $payload;
        $this->client->submit($form);
        $this->assertResponseRedirects();

        // Pending: the admin question shows in the applicant's profile thread + inbox.
        $this->client->loginUser($applicant);
        $this->assertEscapedAt('/list', $payload);
        $this->assertEscapedAt('/messages', $payload);

        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $crawler = $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $form = $crawler->filter('form[action$="/deny"]')->form();
        $form['reason'] = $payload;
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $this->assertSame(ApiApplicationStatus::Denied, $this->em()->getRepository(ApiApplication::class)->find($app->id)->status);

        // Denied: the reason is shown in the profile and sent as a message.
        $this->client->loginUser($applicant);
        $this->assertEscapedAt('/list', $payload);
        $this->assertEscapedAt('/messages', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testApiUsageLogIsEscapedForAdmin(string $payload): void
    {
        $app = ApiApplicationFactory::createOne(['appName' => $payload]);
        $log = new ApiUsageLog();
        $log->apiApplication = $this->em()->getRepository(ApiApplication::class)->find($app->id);
        $log->method = 'POST';
        $log->routeName = null;
        $log->path = '/api/v1/' . $payload;
        $log->statusCode = 201;
        $log->requestPayload = ['title' => $payload];
        $this->em()->persist($log);
        $this->em()->flush();

        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $this->assertEscapedAt('/admin/api-usage', $payload);
        $this->assertEscapedAt('/admin/api-usage?q=' . rawurlencode($payload) . '&method=' . rawurlencode($payload), $payload, expectEscapedForm: false);
    }
}
