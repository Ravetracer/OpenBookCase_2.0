<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Entity\Rating;
use App\Entity\WatchlistItem;
use App\Enums\AccessibilityLevel;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\CaretakerFactory;
use App\Tests\Factory\OpeningTimeFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Factory\WishlistItemFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class BookcaseApiTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Assert the last response was NOT a 2xx (auth-gated endpoint denied access). */
    private function assertAccessDenied(): void
    {
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $status < 200 || $status >= 300,
            "Expected a non-2xx (access-denied) status, got $status",
        );
    }

    // ---------------------------------------------------------------------
    // GET /api/bookcase/  (bounding box)
    // ---------------------------------------------------------------------

    public function testBoundingBoxMissingParamsReturns400(): void
    {
        $this->client->request('GET', '/api/bookcase/');
        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $this->json());
    }

    public function testBoundingBoxReturnsOnlyEntriesInsideBox(): void
    {
        // Inside the box (Germany centre-ish).
        BookcaseFactory::new()->at(51.0, 10.0)->create(['title' => 'Inside A']);
        BookcaseFactory::new()->at(51.5, 10.5)->create(['title' => 'Inside B']);
        // Outside the box.
        BookcaseFactory::new()->at(40.0, 10.0)->create(['title' => 'Outside South']);
        BookcaseFactory::new()->at(51.0, 2.0)->create(['title' => 'Outside West']);

        $this->client->request('GET', '/api/bookcase/?latMin=50&latMax=52&lonMin=9&lonMax=11');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('offset', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('markers', $data);

        $titles = array_column($data['markers'], 'title');
        $this->assertContains('Inside A', $titles);
        $this->assertContains('Inside B', $titles);
        $this->assertNotContains('Outside South', $titles);
        $this->assertNotContains('Outside West', $titles);
        $this->assertSame(count($data['markers']), $data['total']);
    }

    public function testBoundingBoxMarkerShape(): void
    {
        $bc = BookcaseFactory::new()
            ->at(51.0, 10.0)
            ->withAccessibility(AccessibilityLevel::Full)
            ->create(['title' => 'Shape Check']);
        // Give it a rating and an open wish so the aggregate fields are exercised.
        RatingFactory::createOne(['bookcase' => $bc, 'value' => 4]);
        WishlistItemFactory::new()->open()->create(['bookcase' => $bc]);

        $this->client->request('GET', '/api/bookcase/?latMin=50&latMax=52&lonMin=9&lonMax=11');
        $this->assertResponseIsSuccessful();

        $markers = $this->json()['markers'];
        $marker = null;
        foreach ($markers as $m) {
            if ($m['title'] === 'Shape Check') {
                $marker = $m;
                break;
            }
        }
        $this->assertNotNull($marker, 'Expected the created bookcase in the marker list');

        foreach (['id', 'title', 'position', 'entryType', 'mapSymbol', 'status',
            'accessibility', 'isMobile', 'isBookcrossingZone', 'ratingCount',
            'ratingAverage', 'openWishlistCount'] as $key) {
            $this->assertArrayHasKey($key, $marker, "marker missing key: $key");
        }
        $this->assertArrayHasKey('latitude', $marker['position']);
        $this->assertArrayHasKey('longitude', $marker['position']);
        $this->assertSame('active', $marker['status']);
        $this->assertSame('green', $marker['accessibility']);
        $this->assertFalse($marker['isMobile']);
        $this->assertSame(1, $marker['ratingCount']);
        $this->assertEqualsWithDelta(4.0, $marker['ratingAverage'], 0.001);
        $this->assertSame(1, $marker['openWishlistCount']);
    }

    // ---------------------------------------------------------------------
    // GET /api/bookcase/search
    // ---------------------------------------------------------------------

    public function testSearchSingleCharReturnsEmpty(): void
    {
        BookcaseFactory::createOne(['title' => 'Alexanderplatz Bookcase']);
        $this->client->request('GET', '/api/bookcase/search?q=A');
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->json());
    }

    public function testSearchFindsBySubstringCaseInsensitive(): void
    {
        $bc = BookcaseFactory::new()->at(48.1, 11.5)->create(['title' => 'Marienplatz Library Box']);
        BookcaseFactory::createOne(['title' => 'Completely Different']);

        $this->client->request('GET', '/api/bookcase/search?q=marienplatz');
        $this->assertResponseIsSuccessful();

        $results = $this->json();
        $this->assertCount(1, $results);
        $this->assertSame((string) $bc->id, $results[0]['id']);
        $this->assertSame('Marienplatz Library Box', $results[0]['title']);
        $this->assertIsFloat($results[0]['latitude']);
        $this->assertIsFloat($results[0]['longitude']);
    }

    public function testSearchCapsAtEightResults(): void
    {
        for ($i = 0; $i < 12; $i++) {
            BookcaseFactory::createOne(['title' => "Zentrale Stelle $i"]);
        }
        $this->client->request('GET', '/api/bookcase/search?q=zentrale');
        $this->assertResponseIsSuccessful();
        $this->assertLessThanOrEqual(8, count($this->json()));
        $this->assertCount(8, $this->json());
    }

    // ---------------------------------------------------------------------
    // GET /api/bookcase/new  (ROLE_USER)
    // ---------------------------------------------------------------------

    public function testNewFragmentRequiresAuth(): void
    {
        $this->client->request('GET', '/api/bookcase/new');
        // Not logged in → security blocks (redirect to login, or 401).
        $this->assertAccessDenied();
    }

    public function testNewFragmentLoggedInRendersForm(): void
    {
        $this->loginAsUser();
        $crawler = $this->client->request('GET', '/api/bookcase/new?lat=51.2&lon=10.3&editable=1');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('#create-form')->count());
        // Prefilled coordinates (rendered with trailing zeros, e.g. "51.20000000000").
        $latInput = $crawler->filter('input[name="bookcase_create[position][latitude]"]');
        $this->assertSame(1, $latInput->count());
        $this->assertEqualsWithDelta(51.2, (float) $latInput->attr('value'), 0.001);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/create  (ROLE_USER, CSRF via form)
    // ---------------------------------------------------------------------

    public function testCreateRequiresAuth(): void
    {
        $this->client->request('POST', '/api/bookcase/create');
        $this->assertAccessDenied();
    }

    public function testCreateBookcase(): void
    {
        $this->loginAsUser();
        $crawler = $this->client->request('GET', '/api/bookcase/new?editable=1');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('#create-form')->form();
        $form['bookcase_create[title]'] = 'Freshly Created Box';
        $form['bookcase_create[entryType]'] = 'bookcase';
        $form['bookcase_create[installationType]'] = 'streetside';
        $form['bookcase_create[position][latitude]'] = '52.5';
        $form['bookcase_create[position][longitude]'] = '13.4';

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->json();
        foreach (['id', 'title', 'latitude', 'longitude', 'entryType', 'mapSymbol',
            'markerStatus', 'accessibility', 'isMobile', 'isBookcrossingZone'] as $key) {
            $this->assertArrayHasKey($key, $data, "create response missing key: $key");
        }
        $this->assertSame('Freshly Created Box', $data['title']);
        $this->assertSame(52.5, $data['latitude']);
        $this->assertSame(13.4, $data['longitude']);
        $this->assertSame('bookcase', $data['entryType']);
        $this->assertSame('standard', $data['mapSymbol']);
        $this->assertSame('active', $data['markerStatus']);

        // The new entry got a short code persisted.
        $created = $this->em()->getRepository(Bookcase::class)->find($data['id']);
        $this->assertNotNull($created);
        $this->assertNotEmpty($created->shortCode);
    }

    public function testCreateRejectsUrlInTitle(): void
    {
        $this->loginAsUser();
        $crawler = $this->client->request('GET', '/api/bookcase/new?editable=1');
        $form = $crawler->filter('#create-form')->form();
        $form['bookcase_create[title]'] = 'Spam https://evil.example.com';
        $form['bookcase_create[position][latitude]'] = '52.5';
        $form['bookcase_create[position][longitude]'] = '13.4';

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('error', $this->json()['status']);
    }

    // ---------------------------------------------------------------------
    // GET /api/bookcase/export  (streamed open-data dump, plain + gzip)
    // ---------------------------------------------------------------------

    /**
     * Body of a streamed response. The test client already runs the stream during
     * request() (so the StreamedResponse is marked sent); the captured bytes live on
     * the BrowserKit internal response, not via a second sendContent().
     */
    private function captureStream(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    public function testExportStreamsJsonWithChildren(): void
    {
        $bc = BookcaseFactory::createOne(['title' => 'Export Me']);
        OpeningTimeFactory::createOne([
            'bookcase' => $bc,
            'open_time' => '24/7',
            'twenty_for_seven' => true,
        ]);
        $caretaker = CaretakerFactory::createOne(['name' => 'Jane Keeper', 'contact' => 'jane@example.test']);
        $bc->addCaretaker($caretaker);
        $this->em()->flush();

        $this->client->request('GET', '/api/bookcase/export');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('openbookcase-export.json', (string) $response->headers->get('Content-Disposition'));

        $data = json_decode($this->captureStream(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['count']);

        $entry = $data['bookcases'][0];
        $this->assertSame('Export Me', $entry['title']);
        $this->assertSame((string) $bc->id, $entry['id']);
        // Children are resolved from the bulk lookup maps, keyed by ULID.
        $this->assertSame('Jane Keeper', $entry['caretakers'][0]['name']);
        $this->assertSame('24/7', $entry['openingTimes'][0]['openTime']);
        $this->assertTrue($entry['openingTimes'][0]['twentyFourSeven']);
    }

    public function testExportGzipReturnsGzippedJson(): void
    {
        BookcaseFactory::createMany(3);

        $this->client->request('GET', '/api/bookcase/export?gzip=1');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertSame('application/gzip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('openbookcase-export.json.gz', (string) $response->headers->get('Content-Disposition'));

        $gz = $this->captureStream();
        $json = gzdecode($gz);
        $this->assertNotFalse($json, 'response body should be valid gzip');

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $data['count']);
        $this->assertCount(3, $data['bookcases']);
    }

    // ---------------------------------------------------------------------
    // GET /api/bookcase/{id}  + /html + /edit
    // ---------------------------------------------------------------------

    public function testRetrieveSingleJson(): void
    {
        $bc = BookcaseFactory::createOne(['title' => 'Single JSON']);
        $this->client->request('GET', '/api/bookcase/' . $bc->id);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('Single JSON', $data['title']);
    }

    public function testRetrieveSingleUnknownReturns404(): void
    {
        // A well-formed but non-existent ULID.
        $this->client->request('GET', '/api/bookcase/01HZZZZZZZZZZZZZZZZZZZZZZZ');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRetrieveDetailHtmlContainsTitle(): void
    {
        $address = new \App\Entity\Embeddables\Address();
        $address->city = 'Berlin';
        $bc = BookcaseFactory::createOne(['title' => 'Detail HTML Title', 'address' => $address]);
        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Detail HTML Title', $this->client->getResponse()->getContent());
    }

    /**
     * Regression: the detail template used to dereference bookcase.address.street
     * without a null guard, so quick-added entries (address = null) 500'd. It must
     * now render cleanly with no address.
     */
    public function testRetrieveDetailHtmlRendersWithoutAddress(): void
    {
        $bc = BookcaseFactory::createOne(['title' => 'No Address Entry', 'address' => null]);
        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No Address Entry', $this->client->getResponse()->getContent());
    }

    /**
     * The detail carousel marks each photo as a lightbox trigger so it can be
     * opened large with prev/next navigation (lightbox_controller.js).
     */
    public function testRetrieveDetailHtmlImagesAreLightboxTriggers(): void
    {
        $bc = BookcaseFactory::createOne(['title' => 'Has Photos']);
        \App\Tests\Factory\ImageFactory::createOne(['bookcase' => $bc, 'filename' => 'photo.jpg']);

        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-lightbox="bc-' . $bc->id . '"', $html);
        $this->assertStringContainsString('/images/photo.jpg', $html);
    }

    public function testEditFragmentRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $this->assertAccessDenied();
    }

    public function testEditFragmentLoggedIn(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Edit Me']);
        $crawler = $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('#edit-form')->count());
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/save  (ROLE_USER, CSRF via form)
    // ---------------------------------------------------------------------

    public function testSaveRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/save');
        $this->assertAccessDenied();
    }

    public function testSaveReturnsMarkerPayload(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Before Save']);
        $id = (string) $bc->id;

        $crawler = $this->client->request('GET', '/api/bookcase/' . $id . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('#edit-form')->form();
        $form['bookcase[title]'] = 'After Save';

        $this->client->submit($form);
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('marker', $data);
        $this->assertSame('After Save', $data['marker']['title']);
        $this->assertArrayHasKey('position', $data['marker']);

        // Persisted.
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertSame('After Save', $reloaded->title);
    }

    public function testEditFormRendersOpeningTimeAddControl(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'Opening Controls']);

        $crawler = $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $this->assertResponseIsSuccessful();

        // The add button + the prototype-bearing collection container must be present,
        // otherwise a user can never add an opening time to an entry that has none.
        $this->assertGreaterThan(0, $crawler->filter('[data-modal-action="add-opening-time"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('[data-opening-time-collection][data-prototype]')->count());
    }

    public function testSaveAddsOpeningTime(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'No Hours Yet']);
        $id = (string) $bc->id;
        $this->assertCount(0, $bc->openingTimes);

        $crawler = $this->client->request('GET', '/api/bookcase/' . $id . '/edit');
        $form = $crawler->filter('#edit-form')->form();

        // The opening-time row is added client-side from the prototype, so inject the
        // field values the JS would have produced and POST the full form payload.
        $values = $form->getPhpValues();
        $values['bookcase']['openingTimes'][0] = [
            'open_time' => '',
            'twenty_for_seven' => '1',
        ];

        $this->client->request('POST', $form->getUri(), $values);
        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status']);

        // Persisted with the bookcase FK set (by_reference=false + cascade persist).
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertCount(1, $reloaded->openingTimes);
        $this->assertTrue($reloaded->openingTimes->first()->twenty_for_seven);
        $this->assertSame($id, (string) $reloaded->openingTimes->first()->bookcase->id);
    }

    public function testSaveRemovesOpeningTime(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Has Hours']);
        OpeningTimeFactory::createOne(['bookcase' => $bc, 'twenty_for_seven' => true]);
        $id = (string) $bc->id;

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertCount(1, $reloaded->openingTimes);

        $crawler = $this->client->request('GET', '/api/bookcase/' . $id . '/edit');
        $form = $crawler->filter('#edit-form')->form();

        // Submit with the openingTimes collection emptied (what the UI sends after the
        // user removes the row) — delete_empty + orphanRemoval should delete it.
        $values = $form->getPhpValues();
        $values['bookcase']['openingTimes'] = [];

        $this->client->request('POST', $form->getUri(), $values);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertCount(0, $reloaded->openingTimes);
    }

    // ---------------------------------------------------------------------
    // DELETE /api/bookcase/{id}  (soft delete, ROLE_USER, JSON reason)
    // ---------------------------------------------------------------------

    public function testDeleteRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('DELETE', '/api/bookcase/' . $bc->id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['reason' => 'x']));
        $this->assertAccessDenied();
    }

    public function testDeleteWithoutReasonFails(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request('DELETE', '/api/bookcase/' . $bc->id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('error', $this->json());
    }

    public function testDeleteWithReasonArchivesAndRemoves(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne(['title' => 'To Be Deleted']);
        $id = (string) $bc->id;

        $this->client->request('DELETE', '/api/bookcase/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['reason' => 'gone forever']));
        $this->assertResponseIsSuccessful();
        $this->assertSame('deleted', $this->json()['status']);

        $this->em()->clear();
        // Live row gone.
        $this->assertNull($this->em()->getRepository(Bookcase::class)->find($id));
        // Archive row created.
        $archive = $this->em()->getRepository(DeletedBookcase::class)->findOneBy(['originalId' => $id]);
        $this->assertNotNull($archive);
        $this->assertSame('To Be Deleted', $archive->title);
        $this->assertSame('gone forever', $archive->reason);
        $this->assertSame($user->getUserIdentifier(), $archive->deletedBy);
        $this->assertNotEmpty($archive->payload);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/rating  (ROLE_USER)
    // ---------------------------------------------------------------------

    public function testRateRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/rating', ['value' => 4]);
        $this->assertResponseStatusCodeSame(401);
    }

    #[DataProvider('outOfRangeRatings')]
    public function testRateOutOfRangeFails(int $value): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/rating', ['value' => $value]);
        $this->assertResponseStatusCodeSame(400);
    }

    public static function outOfRangeRatings(): array
    {
        return [[0], [6], [-1], [99]];
    }

    public function testRateValidPersists(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;
        // A pre-existing rating by another user.
        RatingFactory::createOne(['bookcase' => $bc, 'value' => 2]);

        $this->client->request('POST', '/api/bookcase/' . $id . '/rating', ['value' => 4]);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('success', $data['status']);
        $this->assertSame(4, $data['userValue']);
        // The response must reflect both ratings live (2 + 4 → avg 3.0).
        $this->assertSame(2, $data['count']);
        $this->assertEqualsWithDelta(3.0, $data['average'], 0.001);

        // Assert the persisted DB state (authoritative) too.
        $this->em()->clear();
        $ratings = $this->em()->getRepository(Rating::class)->findBy(['bookcase' => $id]);
        $this->assertCount(2, $ratings, 'Both the pre-existing and the new rating must exist');
        $values = array_map(static fn (Rating $r) => $r->value, $ratings);
        sort($values);
        $this->assertSame([2, 4], $values);
    }

    /**
     * Regression: the very first rating on a bookcase used to return count:0,
     * average:0 in the JSON (stale lazy collection) even though the row was
     * committed. The response must now reflect it immediately.
     */
    public function testFirstRatingResponseReflectsItImmediately(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/rating', ['value' => 5]);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame(1, $data['count'], 'first rating must be counted in the response');
        $this->assertEqualsWithDelta(5.0, $data['average'], 0.001);
        $this->assertSame(5, $data['rounded']);
    }

    public function testRateUpsertsForSameUser(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;

        $this->client->request('POST', '/api/bookcase/' . $id . '/rating', ['value' => 1]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->json()['userValue']);

        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(Rating::class)->findBy(['bookcase' => $id]));

        // Second rating by the same user must UPDATE the existing row, not add one.
        $this->client->request('POST', '/api/bookcase/' . $id . '/rating', ['value' => 5]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(5, $this->json()['userValue']);

        $this->em()->clear();
        $ratings = $this->em()->getRepository(Rating::class)->findBy(['bookcase' => $id]);
        $this->assertCount(1, $ratings, 'A second rating by the same user must update, not duplicate');
        $this->assertSame(5, $ratings[0]->value);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/position  (ROLE_USER)
    // ---------------------------------------------------------------------

    public function testMoveRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/position', ['latitude' => 50, 'longitude' => 10]);
        $this->assertResponseStatusCodeSame(401);
    }

    #[DataProvider('invalidPositions')]
    public function testMoveInvalidPositionFails(mixed $lat, mixed $lon): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/position', ['latitude' => $lat, 'longitude' => $lon]);
        $this->assertResponseStatusCodeSame(400);
    }

    public static function invalidPositions(): array
    {
        return [
            'lat too high' => [91, 10],
            'lat too low' => [-91, 10],
            'lon too high' => [10, 181],
            'lon too low' => [10, -181],
            'non-numeric' => ['abc', 'def'],
        ];
    }

    public function testMoveValidUpdatesPosition(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create();
        $id = (string) $bc->id;

        $this->client->request('POST', '/api/bookcase/' . $id . '/position', ['latitude' => 48.137, 'longitude' => 11.575]);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame(48.137, $data['latitude']);
        $this->assertSame(11.575, $data['longitude']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertSame(48.137, $reloaded->position->latitude);
        $this->assertSame(11.575, $reloaded->position->longitude);
    }

    // ---------------------------------------------------------------------
    // POST/DELETE /api/bookcase/{id}/watch  (ROLE_USER)
    // ---------------------------------------------------------------------

    public function testWatchRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/watch');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('DELETE', '/api/bookcase/' . $bc->id . '/watch');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testWatchToggleOnThenOff(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;

        $this->client->request('POST', '/api/bookcase/' . $id . '/watch');
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['watching']);

        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(WatchlistItem::class)->findBy(['bookcase' => $id]));

        // Idempotent add: posting again must not duplicate.
        $this->client->request('POST', '/api/bookcase/' . $id . '/watch');
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->assertCount(1, $this->em()->getRepository(WatchlistItem::class)->findBy(['bookcase' => $id]));

        $this->client->request('DELETE', '/api/bookcase/' . $id . '/watch');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->json()['watching']);

        $this->em()->clear();
        $this->assertCount(0, $this->em()->getRepository(WatchlistItem::class)->findBy(['bookcase' => $id]));
    }
}
