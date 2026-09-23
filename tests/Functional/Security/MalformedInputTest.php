<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Hostile / malformed input against the website endpoints (session-auth'd JSON
 * API, list view, profile, locale switch). Invariant: bad input yields a clean
 * 4xx (or a safely-sanitised success) — never a 500, and never a write that
 * goes beyond what a valid request could do.
 */
final class MalformedInputTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function assertClientError(string $context = ''): void
    {
        $status = $this->httpStatus();
        $this->assertGreaterThanOrEqual(400, $status, "$context: expected a 4xx, got $status");
        $this->assertLessThan(500, $status, "$context: expected a 4xx, got $status (server error)");
    }

    private function assertNotServerError(string $context = ''): void
    {
        $this->assertLessThan(500, $this->httpStatus(), "$context: must not cause a server error");
    }

    private function reloadBookcase(Bookcase $bc): ?Bookcase
    {
        $this->em()->clear();

        return $this->em()->getRepository(Bookcase::class)->find($bc->id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'garbage' => ['not-a-ulid'];
        yield 'sql' => ["1' OR '1'='1"];
        yield 'numeric' => ['12345'];
        yield 'nonexistent ulid' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'nonexistent uuid form' => ['0188f1f0-8c7a-7b3e-9f1a-2b3c4d5e6f70'];
        yield 'null byte' => ["01ARZ3NDEKTSV4RRFFQ69G5FA\0"];
        yield 'too long' => [str_repeat('Z', 300)];
    }

    // ── Invalid ids → 404 ─────────────────────────────────────────────────

    #[DataProvider('invalidIds')]
    public function testInvalidBookcaseIdsReturn404(string $id): void
    {
        $this->loginAsUser();
        $seg = rawurlencode($id);

        foreach (['', '/html', '/edit', '/photos', '/wishlist'] as $suffix) {
            $this->client->request('GET', "/api/bookcase/$seg$suffix");
            $this->assertSame(404, $this->httpStatus(), "GET /api/bookcase/{id}$suffix with '$id'");
        }

        foreach (['/rating' => ['value' => 3], '/position' => ['latitude' => 1, 'longitude' => 1], '/save' => [], '/watch' => [], '/wishlist' => ['title' => 'x']] as $suffix => $body) {
            $this->client->request('POST', "/api/bookcase/$seg$suffix", $body);
            $this->assertSame(404, $this->httpStatus(), "POST /api/bookcase/{id}$suffix with '$id'");
        }

        $this->client->request('DELETE', "/api/bookcase/$seg", [], [], ['CONTENT_TYPE' => 'application/json'], '{"reason":"x"}');
        $this->assertSame(404, $this->httpStatus(), "DELETE /api/bookcase/{id} with '$id'");
    }

    #[DataProvider('invalidIds')]
    public function testInvalidSubResourceIdsReturn404(string $id): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $seg = rawurlencode($id);

        $this->client->request('POST', "/api/bookcase/{$bc->id}/wishlist/$seg/status", ['action' => 'drop']);
        $this->assertSame(404, $this->httpStatus(), 'wishlist item status');
        $this->client->request('DELETE', "/api/bookcase/{$bc->id}/wishlist/$seg");
        $this->assertSame(404, $this->httpStatus(), 'wishlist item delete');
        $this->client->request('POST', "/api/bookcase/{$bc->id}/image/$seg/rotate", ['direction' => 'cw']);
        $this->assertSame(404, $this->httpStatus(), 'image rotate');
        $this->client->request('POST', "/api/bookcase/{$bc->id}/image/$seg/alt", ['altText' => 'x']);
        $this->assertSame(404, $this->httpStatus(), 'image alt');
        $this->client->request('DELETE', "/api/bookcase/{$bc->id}/image/$seg");
        $this->assertSame(404, $this->httpStatus(), 'image delete');
    }

    #[DataProvider('invalidIds')]
    public function testDeepLinksWithInvalidIdsFallBackToMap(string $id): void
    {
        $this->client->request('GET', '/bookcase/' . rawurlencode($id));
        $this->assertContains($this->httpStatus(), [200, 404], 'deep link');

        $this->client->request('GET', '/s/' . rawurlencode($id));
        $this->assertContains($this->httpStatus(), [200, 404], 'short link');
    }

    public function testShortLinkWithHugeNumericCodeDoesNotOverflow(): void
    {
        $this->client->request('GET', '/s/' . str_repeat('9', 40));
        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('invalidIds')]
    public function testAdminInvalidIdsReturn404(string $id): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create());
        $seg = rawurlencode($id);

        $this->client->request('GET', "/admin/users/$seg");
        $this->assertSame(404, $this->httpStatus(), 'admin user');
        $this->client->request('GET', "/admin/api-applications/$seg");
        $this->assertSame(404, $this->httpStatus(), 'admin api application');
    }

    // ── Rating value ──────────────────────────────────────────────────────

    /** @return iterable<string, array{mixed}> */
    public static function badRatings(): iterable
    {
        yield 'zero' => ['0'];
        yield 'six' => ['6'];
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'empty' => [''];
        yield 'exponent' => ['1e1'];
        yield 'huge' => ['99999999999999999999'];
    }

    #[DataProvider('badRatings')]
    public function testRatingOutOfRangeIsRejected(string $value): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/rating", ['value' => $value]);
        $this->assertClientError("rating '$value'");
        $this->assertSame(0, $this->em()->getRepository(Rating::class)->count([]));
    }

    public function testRatingArrayValueIsRejected(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/rating", ['value' => ['5']]);
        $this->assertClientError('rating array');
        $this->assertSame(0, $this->em()->getRepository(Rating::class)->count([]));
    }

    /** @return iterable<string, array{string}> */
    public static function coercedRatings(): iterable
    {
        yield 'fraction' => ['3.5'];
        yield 'numeric prefix sql' => ['5 OR 1=1'];
        yield 'padded' => [' 4 '];
    }

    /** Loosely-numeric values are coerced by (int) — they must still land on an integer 1..5. */
    #[DataProvider('coercedRatings')]
    public function testCoercedRatingIsSanitisedIntoRange(string $value): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/rating", ['value' => $value]);
        $this->assertNotServerError("rating '$value'");
        foreach ($this->em()->getRepository(Rating::class)->findAll() as $rating) {
            $this->assertIsInt($rating->value);
            $this->assertGreaterThanOrEqual(1, $rating->value);
            $this->assertLessThanOrEqual(5, $rating->value);
        }
    }

    // ── Marker reposition (/position) ─────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function badCoordinates(): iterable
    {
        yield 'lat too high' => ['91', '10'];
        yield 'lat too low' => ['-91', '10'];
        yield 'lon too high' => ['50', '181'];
        yield 'lon too low' => ['50', '-181'];
        yield 'text' => ['abc', 'def'];
        yield 'NaN' => ['NaN', 'NaN'];
        yield 'INF' => ['INF', '-INF'];
        yield 'overflow' => ['1e309', '1e309'];
        yield 'negative overflow' => ['-1e309', '0'];
        yield 'empty' => ['', ''];
        yield 'sql' => ['1 OR 1=1', '1; DROP TABLE bookcase'];
        yield 'hex' => ['0x1A', '0x1A'];
    }

    #[DataProvider('badCoordinates')]
    public function testPositionRejectsBadCoordinates(string $lat, string $lon): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/position", ['latitude' => $lat, 'longitude' => $lon]);
        $this->assertSame(400, $this->httpStatus(), "position ($lat, $lon)");

        $fresh = $this->reloadBookcase($bc);
        $this->assertSame(50.0, $fresh->position->latitude);
        $this->assertSame(10.0, $fresh->position->longitude);
    }

    public function testPositionRejectsArrayCoordinates(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/position", ['latitude' => ['1'], 'longitude' => ['1']]);
        $this->assertClientError('position array');
        $this->assertSame(50.0, $this->reloadBookcase($bc)->position->latitude);
    }

    // ── Bounding box numerics ─────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function weirdBoxes(): iterable
    {
        yield 'text' => ['abc'];
        yield 'NaN' => ['NaN'];
        yield 'overflow' => ['1e309'];
        yield 'negative overflow' => ['-1e309'];
        yield 'huge int' => ['99999999999999999999999999'];
        yield 'out of range' => ['181'];
        yield 'empty' => [''];
    }

    #[DataProvider('weirdBoxes')]
    public function testBoundingBoxNumericGarbageIsSafe(string $v): void
    {
        BookcaseFactory::new()->at(50.0, 10.0)->create();

        $this->client->request('GET', '/api/bookcase/', ['latMin' => $v, 'latMax' => $v, 'lonMin' => $v, 'lonMax' => $v]);
        $this->assertSame(200, $this->httpStatus(), "bbox $v");
        $this->assertIsArray($this->json()['markers'] ?? null);

        // Infinite box around everything is a legitimate (if huge) request; its
        // page size must still be capped.
        $this->client->request('GET', '/api/bookcase/', [
            'latMin' => '-1e309', 'latMax' => '1e309', 'lonMin' => '-1e309', 'lonMax' => '1e309',
            'limit' => $v, 'offset' => $v,
        ]);
        $this->assertSame(200, $this->httpStatus(), "bbox limit/offset $v");
        $data = $this->json();
        $this->assertGreaterThanOrEqual(1, $data['limit']);
        $this->assertLessThanOrEqual(5000, $data['limit']);
        $this->assertGreaterThanOrEqual(0, $data['offset']);
    }

    public function testBoundingBoxLimitIsCapped(): void
    {
        $this->client->request('GET', '/api/bookcase/', ['latMin' => 0, 'latMax' => 1, 'lonMin' => 0, 'lonMax' => 1, 'limit' => 100000, 'offset' => -5]);
        $this->assertSame(5000, $this->json()['limit']);
        $this->assertSame(0, $this->json()['offset']);

        $this->client->request('GET', '/api/bookcase/', ['latMin' => 0, 'latMax' => 1, 'lonMin' => 0, 'lonMax' => 1, 'limit' => -1]);
        $this->assertSame(1, $this->json()['limit']);
    }

    public function testApiV1BoundingBoxLimitIsCapped(): void
    {
        $this->client->request('GET', '/api/v1/bookcases', ['latMin' => 0, 'latMax' => 1, 'lonMin' => 0, 'lonMax' => 1, 'limit' => 100000, 'offset' => -5]);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(1000, $this->json()['limit']);
        $this->assertSame(0, $this->json()['offset']);

        $this->client->request('GET', '/api/v1/bookcases', ['latMin' => '1e309', 'latMax' => 'NaN', 'lonMin' => '-1e309', 'lonMax' => 'abc']);
        $this->assertSame(200, $this->httpStatus());
    }

    // ── /list numerics ────────────────────────────────────────────────────

    /** @return iterable<string, array{array<string, string>}> */
    public static function weirdListParams(): iterable
    {
        yield 'negative page' => [['page' => '-5']];
        yield 'huge page' => [['page' => '99999999999999999999']];
        yield 'text page' => [['page' => 'abc']];
        yield 'perPage huge' => [['perPage' => '100000']];
        yield 'perPage negative' => [['perPage' => '-1']];
        yield 'perPage zero' => [['perPage' => '0']];
        yield 'distance overflow' => [['sort' => 'distance', 'userLat' => '1e309', 'userLon' => '1e309']];
        yield 'distance out of range' => [['sort' => 'distance', 'userLat' => '91', 'userLon' => '-181']];
        yield 'distance NaN' => [['sort' => 'distance', 'userLat' => 'NaN', 'userLon' => 'NaN']];
        yield 'distance huge' => [['sort' => 'distance', 'userLat' => '1e300', 'userLon' => '-1e300']];
        yield 'minRating overflow' => [['minRating' => '1e309']];
        yield 'minRating negative' => [['minRating' => '-99999999999999999999']];
        yield 'bool garbage' => [['wishes' => 'maybe', 'bcz' => '2', 'watch' => 'yes']];
    }

    /** @param array<string, string> $params */
    #[DataProvider('weirdListParams')]
    public function testListNumericGarbageIsSafe(array $params): void
    {
        BookcaseFactory::createMany(3);

        foreach (['/list', '/list/fragment'] as $url) {
            $this->client->request('GET', $url, $params);
            $this->assertNotServerError($url . '?' . http_build_query($params));
        }
    }

    public function testListPerPageIsCappedToWhitelist(): void
    {
        BookcaseFactory::createMany(30);

        $this->client->request('GET', '/list/fragment', ['perPage' => '100000']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(25, $this->client->getCrawler()->filter('[data-bc-open]')->count());
    }

    public function testDistanceSortWithOverflowCoordinatesDoesNotLeakNan(): void
    {
        BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Somewhere']);

        $this->client->request('GET', '/list/fragment', ['sort' => 'distance', 'userLat' => '1e309', 'userLon' => '0']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Somewhere', (string) $this->client->getResponse()->getContent());

        // The filtered export shares the same parsing and is streamed.
        $this->client->request('GET', '/api/bookcase/export', ['filtered' => '1', 'sort' => 'distance', 'userLat' => '1e309', 'userLon' => '0']);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getInternalResponse()->getContent(), true);
        $this->assertIsArray($data, 'filtered export with overflow coordinates must still be valid JSON');
        $this->assertSame(1, $data['count']);
    }

    // ── Type confusion: arrays for scalars ────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function arrayQueryParams(): iterable
    {
        foreach (['q', 'sort', 'dir', 'page', 'perPage', 'userLat', 'userLon', 'acc', 'status', 'type', 'mob', 'minRating', 'osm', 'wishes', 'bcz', 'watch'] as $key) {
            yield "/list/fragment $key" => ['/list/fragment', $key];
        }
        yield '/list q' => ['/list', 'q'];
        yield 'search q' => ['/api/bookcase/search', 'q'];
        yield 'bbox latMin' => ['/api/bookcase/', 'latMin'];
        yield 'bbox limit' => ['/api/bookcase/', 'limit'];
        yield 'v1 bbox latMin' => ['/api/v1/bookcases', 'latMin'];
        yield 'v1 bbox limit' => ['/api/v1/bookcases', 'limit'];
        yield 'export gzip' => ['/api/bookcase/export', 'gzip'];
        yield 'export filtered' => ['/api/bookcase/export', 'filtered'];
        yield 'new lat' => ['/api/bookcase/new', 'lat'];
        yield 'new editable' => ['/api/bookcase/new', 'editable'];
    }

    #[DataProvider('arrayQueryParams')]
    public function testArrayQueryParamsAreRejectedCleanly(string $url, string $key): void
    {
        $this->loginAsUser();
        $params = ['latMin' => '0', 'latMax' => '1', 'lonMin' => '0', 'lonMax' => '1', 'lat' => '1', 'lon' => '1'];
        $params[$key] = ['x', 'y'];

        $this->client->request('GET', $url, $params);
        $this->assertNotServerError("$url {$key}[]");
    }

    public function testNestedArrayQueryIsRejectedCleanly(): void
    {
        $this->client->request('GET', '/list/fragment?q[a][b][c][d]=1&sort[]=title');
        $this->assertClientError('nested q');
    }

    public function testWishlistAddRejectsArrayTitle(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/wishlist", ['title' => ['x'], 'author' => ['y']]);
        $this->assertClientError('wishlist title[]');
        $this->assertSame(0, $this->em()->getRepository(WishlistItem::class)->count([]));
    }

    /** @return array<string, mixed> quick-add form values incl. a valid CSRF token */
    private function createFormValues(): array
    {
        $crawler = $this->client->request('GET', '/api/bookcase/new');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('#create-form')->form();
        $form['bookcase_create[title]'] = 'Valid Title';
        $form['bookcase_create[position][latitude]'] = '52.5';
        $form['bookcase_create[position][longitude]'] = '13.4';

        return $form->getPhpValues();
    }

    public function testCreateBaselineWorks(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/api/bookcase/create', $this->createFormValues());
        $this->assertSame(201, $this->httpStatus());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badCreateFields(): iterable
    {
        yield 'title array' => ['title', ['x']];
        yield 'title 100k' => ['title', str_repeat('A', 100_000)];
        yield 'lat array' => ['position.latitude', ['1']];
        yield 'lat text' => ['position.latitude', 'abc'];
        yield 'lat out of range' => ['position.latitude', '91'];
        yield 'lon out of range' => ['position.longitude', '-181'];
        yield 'lat overflow' => ['position.latitude', '1e309'];
        yield 'lat NaN' => ['position.latitude', 'NaN'];
        yield 'entryType junk' => ['entryType', "bookcase' OR 1=1"];
        yield 'entryType array' => ['entryType', ['bookcase']];
    }

    #[DataProvider('badCreateFields')]
    public function testCreateRejectsMalformedFields(string $field, mixed $value): void
    {
        $this->loginAsUser();
        $values = $this->createFormValues();
        $path = explode('.', $field);
        if (count($path) === 2) {
            $values['bookcase_create'][$path[0]][$path[1]] = $value;
        } else {
            $values['bookcase_create'][$path[0]] = $value;
        }

        $this->client->request('POST', '/api/bookcase/create', $values);
        $this->assertClientError("create $field");
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]), "create $field must not persist");
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badSaveFields(): iterable
    {
        yield 'url in title' => ['title', 'Spam https://evil.example'];
        yield 'title array' => ['title', ['x']];
        yield 'title 100k' => ['title', str_repeat('T', 100_000)];
        yield 'lat out of range' => ['position.latitude', '91'];
        yield 'lat text' => ['position.latitude', 'abc'];
    }

    #[DataProvider('badSaveFields')]
    public function testSaveRejectsMalformedFieldsWithoutPersisting(string $field, mixed $value): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Before Save']);

        $crawler = $this->client->request('GET', "/api/bookcase/{$bc->id}/edit");
        $this->assertResponseIsSuccessful();
        $values = $crawler->filter('#edit-form')->form()->getPhpValues();
        $path = explode('.', $field);
        if (count($path) === 2) {
            $values['bookcase'][$path[0]][$path[1]] = $value;
        } else {
            $values['bookcase'][$path[0]] = $value;
        }

        $this->client->request('POST', "/api/bookcase/{$bc->id}/save", $values);
        $this->assertClientError("save $field");

        $fresh = $this->reloadBookcase($bc);
        $this->assertSame('Before Save', $fresh->title);
        $this->assertSame(50.0, $fresh->position->latitude);
    }

    public function testCreateWithWholeFormAsScalarIsRejected(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/api/bookcase/create', ['bookcase_create' => 'x']);
        $this->assertClientError('form as scalar');
    }

    // ── Soft delete JSON body ─────────────────────────────────────────────

    /** @return iterable<string, array{string|null}> */
    public static function badDeleteBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['reason=spam'];
        yield 'json null' => ['null'];
        yield 'json string' => ['"spam"'];
        yield 'json number' => ['42'];
        yield 'reason null' => ['{"reason":null}'];
        yield 'reason empty' => ['{"reason":"   "}'];
        yield 'reason object' => ['{"reason":{"a":1}}'];
        yield 'reason list' => ['{"reason":["spam"]}'];
        yield 'truncated' => ['{"reason":"sp'];
        yield 'deeply nested' => [str_repeat('[', 10_000) . str_repeat(']', 10_000)];
        yield 'invalid utf8' => ["{\"reason\":\"\xff\xfe\"}"];
    }

    #[DataProvider('badDeleteBodies')]
    public function testDeleteRejectsMalformedBodies(string $body): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('DELETE', "/api/bookcase/{$bc->id}", [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $this->assertClientError('delete body ' . substr($body, 0, 30));

        $this->assertNotNull($this->reloadBookcase($bc), 'bookcase must survive a malformed delete');
        $this->assertSame(0, $this->em()->getRepository(DeletedBookcase::class)->count([]));
    }

    // ── Very long / binary strings ────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function nastyStrings(): iterable
    {
        yield '100k' => [str_repeat('a', 100_000)];
        yield 'null byte' => ["Alpha\0Beta"];
        yield 'invalid utf8' => ["\xff\xfe\xfd"];
        yield 'overlong utf8' => ["\xc0\xaf"];
        yield 'lone surrogate' => ["\xed\xa0\x80"];
        yield 'rtl override' => ["\u{202E}gnp.exe"];
    }

    #[DataProvider('nastyStrings')]
    public function testSearchAndListSurviveNastyStrings(string $value): void
    {
        BookcaseFactory::createOne(['title' => 'Alpha Box']);
        $this->client->loginUser(UserFactory::new()->admin()->create());

        $errors = [];
        foreach (['/api/bookcase/search', '/list', '/list/fragment', '/admin/users', '/admin/api-usage'] as $url) {
            $this->client->request('GET', $url, ['q' => $value]);
            if ($this->httpStatus() >= 500) {
                $errors[] = "$url → {$this->httpStatus()}";
            }
        }
        $this->client->request('GET', '/api/bookcase/export', ['filtered' => '1', 'q' => $value]);
        $body = (string) $this->client->getInternalResponse()->getContent();
        if ($this->httpStatus() >= 500 || !is_array(json_decode($body, true))) {
            $errors[] = '/api/bookcase/export?filtered=1 → broken stream';
        }

        $this->assertSame([], $errors, 'server errors for hostile q');
    }

    public function testWishlistRejectsHugeTitle(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}/wishlist", ['title' => str_repeat('W', 100_000)]);
        $this->assertClientError('wishlist 100k title');
        $this->assertSame(0, $this->em()->getRepository(WishlistItem::class)->count([]));
    }

    // ── /profile/home ─────────────────────────────────────────────────────

    private function profileHomeToken(): string
    {
        $crawler = $this->client->request('GET', '/');
        $node = $crawler->filter('#profileModal form[data-action*="updateHome"] input[name="_token"]');
        $this->assertGreaterThan(0, $node->count());

        return (string) $node->attr('value');
    }

    /** @return iterable<string, array{string, string}> */
    public static function badStrictCoordinates(): iterable
    {
        yield 'lat too high' => ['91', '10'];
        yield 'lat too low' => ['-91', '10'];
        yield 'lon too high' => ['50', '181'];
        yield 'lon too low' => ['50', '-181'];
        yield 'overflow' => ['1e309', '0'];
        yield 'negative overflow' => ['0', '-1e309'];
    }

    #[DataProvider('badStrictCoordinates')]
    public function testProfileHomeRejectsOutOfRange(string $lat, string $lon): void
    {
        $user = $this->loginAsUser();
        $token = $this->profileHomeToken();

        $this->client->request('POST', '/profile/home', ['_token' => $token, 'enabled' => '1', 'latitude' => $lat, 'longitude' => $lon, 'zoom' => '10']);
        $this->assertSame(400, $this->httpStatus(), "home ($lat, $lon)");

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(User::class)->find($user->id)->homeLatitude);
    }

    /** Non-numeric junk is coerced; whatever is stored must still be in range. */
    #[DataProvider('badCoordinates')]
    public function testProfileHomeNeverStoresOutOfRangeValues(string $lat, string $lon): void
    {
        $user = $this->loginAsUser();
        $token = $this->profileHomeToken();

        $this->client->request('POST', '/profile/home', ['_token' => $token, 'enabled' => '1', 'latitude' => $lat, 'longitude' => $lon, 'zoom' => '99999999999999999999']);
        $this->assertNotServerError("home ($lat, $lon)");

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($user->id);
        if ($fresh->homeLatitude !== null) {
            $this->assertTrue(is_finite($fresh->homeLatitude) && abs($fresh->homeLatitude) <= 90);
            $this->assertTrue(is_finite($fresh->homeLongitude) && abs($fresh->homeLongitude) <= 180);
            $this->assertGreaterThanOrEqual(1, $fresh->homeZoom);
            $this->assertLessThanOrEqual(19, $fresh->homeZoom);
        }
    }

    public function testProfileHomeRejectsArrayFields(): void
    {
        $this->loginAsUser();
        $token = $this->profileHomeToken();

        $this->client->request('POST', '/profile/home', ['_token' => $token, 'enabled' => ['1'], 'latitude' => ['1'], 'longitude' => '1']);
        $this->assertClientError('home arrays');

        $this->client->request('POST', '/profile/home', ['_token' => [$token]]);
        $this->assertClientError('home token array');
    }

    // ── Locale switch / Accept-Language ───────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function badLocales(): iterable
    {
        yield 'unknown' => ['xx'];
        yield 'traversal' => ['..%2F..%2Fetc%2Fpasswd'];
        yield 'dotdot' => ['..'];
        yield 'null byte' => ['de%00'];
        yield 'long' => [str_repeat('a', 500)];
        yield 'case' => ['DE_de'];
        yield 'sql' => ["de'%20OR%201=1"];
        yield 'script' => ['%3Cscript%3E'];
    }

    #[DataProvider('badLocales')]
    public function testLanguageSwitchRejectsJunkLocales(string $locale): void
    {
        $user = $this->loginAsUser(['language' => 'en']);

        $this->client->request('GET', '/language/' . $locale);
        $this->assertSame(404, $this->httpStatus(), "locale '$locale'");
        $this->assertNull($this->client->getResponse()->headers->getCookies()[0] ?? null, 'no locale cookie for junk');

        $this->em()->clear();
        $this->assertSame('en', $this->em()->getRepository(User::class)->find($user->id)->language);
    }

    /** @return iterable<string, array{string}> */
    public static function badAcceptLanguages(): iterable
    {
        yield 'traversal' => ['../../etc/passwd'];
        yield 'garbage q' => ['de;q=abc, en;q=-1, *;q=9e999'];
        yield 'huge' => [str_repeat('de-DE,', 5000)];
        yield 'binary' => ["\xff\xfe\x00"];
        yield 'empty items' => [',,,;;;'];
    }

    #[DataProvider('badAcceptLanguages')]
    public function testGarbageAcceptLanguageAndLocaleCookie(string $header): void
    {
        $this->client->request('GET', '/', [], [], ['HTTP_ACCEPT_LANGUAGE' => $header]);
        $this->assertResponseIsSuccessful();

        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('obc_locale', '../../' . rawurlencode($header)));
        $this->client->request('GET', '/list');
        $this->assertResponseIsSuccessful();
    }

    // ── HTTP method confusion ─────────────────────────────────────────────

    public function testStateChangingBookcaseRoutesRejectGet(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Stable']);

        foreach (['/save', '/position?latitude=1&longitude=1', '/rating?value=5', '/watch'] as $suffix) {
            $this->client->request('GET', "/api/bookcase/{$bc->id}$suffix");
            $this->assertSame(405, $this->httpStatus(), "GET $suffix");
        }

        $fresh = $this->reloadBookcase($bc);
        $this->assertSame(50.0, $fresh->position->latitude);
        $this->assertSame(0, $this->em()->getRepository(Rating::class)->count([]));
    }

    public function testMethodParameterOverrideCannotTurnPostIntoDelete(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}?_method=DELETE", ['_method' => 'DELETE', 'reason' => 'spam']);
        $this->assertClientError('_method override');
        $this->assertNotNull($this->reloadBookcase($bc));
    }

    /**
     * Symfony always honours the X-HTTP-Method-Override header on POST. A custom
     * header forces a CORS preflight cross-site, and the same-origin guard must
     * still see the overridden (unsafe) method and block a cross-origin attempt.
     */
    public function testHeaderMethodOverrideIsStillSubjectToSameOriginGuard(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', "/api/bookcase/{$bc->id}", [], [], [
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            'HTTP_ORIGIN' => 'https://evil.example',
            'CONTENT_TYPE' => 'application/json',
        ], '{"reason":"spam"}');
        $this->assertSame(403, $this->httpStatus());
        $this->assertNotNull($this->reloadBookcase($bc));
    }

    public function testGetOnCreateDoesNotCreate(): void
    {
        $this->loginAsUser();
        $this->client->request('GET', '/api/bookcase/create?bookcase_create[title]=x');
        $this->assertClientError('GET create');
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]));
    }

    public function testProfileMutationsRejectGet(): void
    {
        $user = $this->loginAsUser();

        foreach (['/profile/delete', '/profile/home?latitude=1&longitude=1&enabled=1', '/profile/email?email=x@example.com', '/profile/language?locale=de', '/profile/notifications'] as $url) {
            $this->client->request('GET', $url);
            $this->assertSame(405, $this->httpStatus(), "GET $url");
        }

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertNotNull($fresh, 'GET must not delete the account');
        $this->assertNull($fresh->homeLatitude);
    }

    public function testAdminMutationsRejectGet(): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create());
        $victim = UserFactory::createOne();

        foreach (['/delete', '/suspend', '/roles', '/email', '/reset-link', '/resend-verification'] as $suffix) {
            $this->client->request('GET', "/admin/users/{$victim->id}$suffix");
            $this->assertSame(405, $this->httpStatus(), "GET admin $suffix");
        }
        $this->client->request('GET', '/admin/users/bulk');
        $this->assertContains($this->httpStatus(), [404, 405], 'GET admin bulk');

        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(User::class)->find($victim->id));
    }

    public function testLanguageSwitchRejectsPost(): void
    {
        $this->client->request('POST', '/language/de');
        $this->assertSame(405, $this->httpStatus());
    }
}
