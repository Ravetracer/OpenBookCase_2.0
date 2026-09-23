<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\Embeddables\Address;
use App\Entity\OpeningTime;
use App\Enums\ActiveStatus;
use App\Enums\NotificationChannel;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stored XSS: every user-editable bookcase field must come out as inert text in
 * every HTML view that renders it (detail/edit fragments, list, deep links),
 * travel as plain data through the JSON endpoints, and link targets
 * (webpage) must never be emitted with a script-executing URL scheme.
 * Covers both seeded data and the real write paths (quick-add, full save).
 */
final class BookcaseXssTest extends FunctionalTestCase
{
    use XssAssertions;

    /** Fields shown in the public detail fragment. */
    private const DETAIL_FIELDS = [
        'title', 'installationType', 'webpage', 'comment',
        'address.street', 'address.houseNumber', 'address.zipcode', 'address.city', 'address.additionalData',
        'accessibility.description', 'active.statusDescription',
        'caretaker.name', 'openingTime.open_time',
    ];

    /** Fields present (as form values) in the edit fragment. */
    private const EDIT_FIELDS = [...self::DETAIL_FIELDS, 'caretaker.contact'];

    /** Fields shown in the /list table. */
    private const LIST_FIELDS = [
        'title', 'address.street', 'address.houseNumber', 'address.zipcode', 'address.city', 'address.additionalData',
    ];

    /** Seed a bookcase whose given field carries the payload (others stay benign). */
    private function seed(string $field, string $payload): Bookcase
    {
        $bc = BookcaseFactory::new()->at(50.1, 8.6)->afterInstantiate(function (Bookcase $b) use ($field, $payload): void {
            $b->address = new Address();
            [$group, $prop] = str_contains($field, '.') ? explode('.', $field, 2) : [null, $field];

            match ($group) {
                null => $b->{$prop} = $payload,
                'address' => $b->address->{$prop} = $payload,
                'accessibility' => $b->accessibility->description = $payload,
                'active' => [$b->active->status = ActiveStatus::Inactive, $b->active->statusDescription = $payload],
                'caretaker' => $b->addCaretaker($this->caretaker($prop, $payload)),
                'openingTime' => $b->openingTimes->add($this->openingTime($b, $payload)),
            };
        })->create();

        return $bc;
    }

    private function caretaker(string $prop, string $payload): Caretaker
    {
        $c = new Caretaker();
        $c->name = 'Friendly Neighbour';
        $c->{$prop} = $payload;

        return $c;
    }

    private function openingTime(Bookcase $b, string $payload): OpeningTime
    {
        $o = new OpeningTime();
        $o->open_time = $payload;
        $o->twenty_for_seven = false;
        $o->bookcase = $b;

        return $o;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private static function cross(array $fields, iterable $payloads): iterable
    {
        $payloads = iterator_to_array($payloads);
        foreach ($fields as $field) {
            foreach ($payloads as $name => [$payload]) {
                yield "$field / $name" => [$field, $payload];
            }
        }
    }

    public static function detailCases(): iterable
    {
        return self::cross(self::DETAIL_FIELDS, self::htmlPayloads());
    }

    public static function editCases(): iterable
    {
        return self::cross(self::EDIT_FIELDS, self::htmlPayloads());
    }

    public static function listCases(): iterable
    {
        return self::cross(self::LIST_FIELDS, self::htmlPayloads());
    }

    // ── HTML views (seeded) ───────────────────────────────────────────────

    #[DataProvider('detailCases')]
    public function testDetailFragmentEscapesField(string $field, string $payload): void
    {
        $bc = $this->seed($field, $payload);

        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('editCases')]
    public function testEditFragmentEscapesField(string $field, string $payload): void
    {
        $this->loginAsUser();
        $bc = $this->seed($field, $payload);

        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('listCases')]
    public function testListPageEscapesField(string $field, string $payload): void
    {
        $this->seed($field, $payload);

        $this->client->request('GET', '/list');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('listCases')]
    public function testListFragmentEscapesField(string $field, string $payload): void
    {
        $this->seed($field, $payload);

        $this->client->request('GET', '/list/fragment');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testDeepLinkAndShortLinkPagesDoNotInjectTitle(string $payload): void
    {
        $bc = $this->seed('title', $payload);

        // The title is not printed on these map pages, but nothing about the entry
        // may leak into them unescaped (meta tags, Stimulus values, …).
        foreach (['/bookcase/' . $bc->id, '/s/' . $bc->shortCode] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
        }
    }

    #[DataProvider('htmlPayloads')]
    public function testProfileWatchlistEscapesBookcaseTitle(string $payload): void
    {
        $user = $this->loginAsUser();
        $bc = $this->seed('title', $payload);
        WatchlistItemFactory::createOne(['user' => $user, 'bookcase' => $bc]);

        // The profile modal (with the watchlist) is rendered into every page.
        $this->client->request('GET', '/list');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    // ── Link targets ──────────────────────────────────────────────────────

    #[DataProvider('dangerousUrls')]
    public function testDetailNeverLinksWebpageWithScriptScheme(string $url): void
    {
        $bc = $this->seed('webpage', $url);

        $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $this->assertNoLiveXss($this->html());
    }

    public function testSavedJavascriptWebpageIsNotRenderedAsClickableLink(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Link Target']);

        $crawler = $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $form = $crawler->filter('#edit-form')->form();
        $form['bookcase[webpage]'] = 'javascript:alert(document.cookie)';
        $this->client->submit($form);
        // Either rejected by validation or stored — but never linked as-is.

        $crawler = $this->client->request('GET', '/api/bookcase/' . $bc->id . '/html');
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $crawler->filter('a[href^="javascript:"]')->count(), 'javascript: webpage rendered as a clickable link');
    }

    // ── Write paths: input → storage → output ─────────────────────────────

    #[DataProvider('htmlPayloads')]
    public function testQuickAddTitleIsEscapedEverywhere(string $payload): void
    {
        $this->loginAsUser();
        $crawler = $this->client->request('GET', '/api/bookcase/new?editable=1');
        $form = $crawler->filter('#create-form')->form();
        $form['bookcase_create[title]'] = $payload;
        $form['bookcase_create[installationType]'] = $payload;
        $form['bookcase_create[position][latitude]'] = '52.5';
        $form['bookcase_create[position][longitude]'] = '13.4';
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(201);

        $response = $this->client->getResponse();
        $this->assertJsonCarriesPayload((string) $response->getContent(), $response->headers->get('Content-Type'), $payload);
        $id = $this->json()['id'];

        foreach (['/api/bookcase/' . $id . '/html', '/api/bookcase/' . $id . '/edit', '/list', '/list/fragment'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $this->assertPayloadEscaped($this->html(), $payload);
        }
    }

    #[DataProvider('htmlPayloads')]
    public function testFullSaveOfEveryTextFieldIsEscaped(string $payload): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Before']);
        $id = (string) $bc->id;

        $crawler = $this->client->request('GET', '/api/bookcase/' . $id . '/edit');
        $values = $crawler->filter('#edit-form')->form()->getPhpValues();
        $b = &$values['bookcase'];
        $b['title'] = $payload;
        $b['webpage'] = $payload;
        $b['installationType'] = $payload;
        $b['comment'] = $payload;
        $b['accessibility']['description'] = $payload;
        $b['active']['status'] = 'inactive';
        $b['active']['statusDescription'] = $payload;
        foreach (['street', 'houseNumber', 'zipcode', 'city', 'additionalData'] as $f) {
            $b['address'][$f] = $payload;
        }
        $b['caretakers'][0] = ['name' => $payload, 'contact' => $payload, 'address' => []];
        $b['openingTimes'][0] = ['open_time' => $payload];
        unset($b);

        $this->client->request('POST', '/api/bookcase/' . $id . '/save', $values);
        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status'], (string) $this->client->getResponse()->getContent());

        foreach (['/api/bookcase/' . $id . '/html', '/api/bookcase/' . $id . '/edit', '/list'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $this->assertPayloadEscaped($this->html(), $payload);
        }

        $this->client->request('GET', '/api/bookcase/' . $id);
        $response = $this->client->getResponse();
        $this->assertJsonCarriesPayload((string) $response->getContent(), $response->headers->get('Content-Type'), $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testWatcherNotificationEscapesEditedTitle(string $payload): void
    {
        $watcher = UserFactory::createOne(['notificationChannel' => NotificationChannel::Both]);
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Watched']);
        WatchlistItemFactory::createOne(['user' => $watcher, 'bookcase' => $bc]);

        $this->loginAsUser();
        $crawler = $this->client->request('GET', '/api/bookcase/' . $bc->id . '/edit');
        $form = $crawler->filter('#edit-form')->form();
        $form['bookcase[title]'] = $payload;
        $this->client->enableProfiler();
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();

        // E-mail notification (HTML part).
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertPayloadEscaped((string) $email->getHtmlBody(), $payload);

        // In-app inbox.
        $this->client->loginUser($watcher);
        $this->client->request('GET', '/messages');
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    // ── JSON endpoints ────────────────────────────────────────────────────

    #[DataProvider('htmlPayloads')]
    public function testJsonEndpointsServePayloadAsData(string $payload): void
    {
        $bc = BookcaseFactory::new()->at(50.1, 8.6)->create(['title' => $payload, 'comment' => $payload]);

        $urls = [
            '/api/bookcase/' . $bc->id,
            '/api/bookcase/search?q=' . rawurlencode($payload),
            '/api/bookcase/?latMin=50&latMax=50.2&lonMin=8.5&lonMax=8.7',
            '/api/v1/bookcases/' . $bc->id,
            '/api/v1/bookcases?latMin=50&latMax=50.2&lonMin=8.5&lonMax=8.7',
        ];
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $response = $this->client->getResponse();
            $this->assertJsonCarriesPayload((string) $response->getContent(), $response->headers->get('Content-Type'), $payload);
        }
    }

    #[DataProvider('htmlPayloads')]
    public function testExportServesPayloadAsJson(string $payload): void
    {
        BookcaseFactory::createOne(['title' => $payload]);

        $this->client->request('GET', '/api/bookcase/export');
        $this->assertResponseIsSuccessful();
        $this->assertJsonCarriesPayload(
            (string) $this->client->getInternalResponse()->getContent(),
            $this->client->getResponse()->headers->get('Content-Type'),
            $payload,
        );
    }
}
