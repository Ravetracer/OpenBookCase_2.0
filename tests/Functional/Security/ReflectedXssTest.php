<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reflected XSS: request data echoed straight back into a response — search
 * boxes, sort/filter params, the login "last username", a re-rendered
 * registration form, arbitrary URL paths/query strings (canonical/og:url) —
 * must be escaped, and JSON endpoints must never answer as HTML.
 */
final class ReflectedXssTest extends FunctionalTestCase
{
    use XssAssertions;

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** Payloads without "/" so they also fit into a single URL path segment. */
    public static function pathSafePayloads(): iterable
    {
        yield 'attribute breakout + img onerror' => ['"><img src=x onerror=alert(1)>'];
        yield 'single-quote attribute breakout' => ["' onmouseover='alert(1)"];
    }

    #[DataProvider('htmlPayloads')]
    public function testListSearchQueryIsEscaped(string $payload): void
    {
        $this->client->request('GET', '/list', ['q' => $payload]);
        $this->assertResponseIsSuccessful();
        // Echoed into the search box and the list controller's Stimulus values.
        $this->assertPayloadEscaped($this->html(), $payload);

        $this->client->request('GET', '/list/fragment', ['q' => $payload]);
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
    }

    #[DataProvider('htmlPayloads')]
    public function testListSortFilterAndPagingParamsAreEscaped(string $payload): void
    {
        $params = [
            'q' => 'x', 'sort' => $payload, 'dir' => $payload, 'page' => $payload, 'perPage' => $payload,
            'userLat' => $payload, 'userLon' => $payload,
            'accessibility' => $payload, 'status' => $payload, 'type' => $payload, 'mobility' => $payload,
            'osm' => $payload, 'rating' => $payload, 'wishlist' => $payload, 'watching' => $payload,
        ];
        foreach (['/list', '/list/fragment'] as $url) {
            $this->client->request('GET', $url, $params);
            $this->assertResponseIsSuccessful();
            $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
        }
    }

    #[DataProvider('htmlPayloads')]
    public function testQuickAddFormPrefillParamsAreEscaped(string $payload): void
    {
        $this->loginAsUser();
        $this->client->request('GET', '/api/bookcase/new', ['lat' => $payload, 'lon' => $payload, 'editable' => $payload]);
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
    }

    #[DataProvider('htmlPayloads')]
    public function testArbitraryQueryStringIsEscapedInCanonicalAndOgUrl(string $payload): void
    {
        foreach (['/', '/list', '/about', '/help'] as $url) {
            $this->client->request('GET', $url . '?' . rawurlencode($payload) . '=' . rawurlencode($payload));
            $this->assertResponseIsSuccessful();
            $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
        }
    }

    #[DataProvider('pathSafePayloads')]
    public function testUnknownDeepLinkIdIsEscaped(string $payload): void
    {
        // Unknown ids fall back to the plain map — the path lands in canonical/og:url.
        $this->client->request('GET', '/bookcase/' . rawurlencode($payload));
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
    }

    #[DataProvider('pathSafePayloads')]
    public function testBogusResetTokenIsEscaped(string $payload): void
    {
        $this->client->followRedirects();
        $this->client->request('GET', '/reset-password/' . rawurlencode($payload));
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
    }

    #[DataProvider('htmlPayloads')]
    public function testFailedLoginLastUsernameIsEscaped(string $payload): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/login"]')->form([
            'username' => $payload,
            'password' => 'wrong-password',
        ]);
        $this->client->submit($form);
        $this->client->followRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // The attempted username is pre-filled into the re-opened login modal.
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testRegistrationFormReRenderIsEscaped(string $payload): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration_form"]')->form();
        $form['registration_form[username]'] = $payload;
        $form['registration_form[email]'] = $payload; // invalid → error + re-render
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        // agreeTerms left unticked → the form is re-rendered with the submitted values.
        $this->client->submit($form);

        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testForgotPasswordEmailIsEscaped(string $payload): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->filter('form[action="/forgot-password"]')->form(['email' => $payload]);
        $this->client->followRedirects();
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload, expectEscapedForm: false);
    }

    #[DataProvider('htmlPayloads')]
    public function testAdminUserSearchQueryIsEscaped(string $payload): void
    {
        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $this->client->request('GET', '/admin/users', ['q' => $payload]);
        $this->assertResponseIsSuccessful();
        $this->assertPayloadEscaped($this->html(), $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function testJsonSearchNeverAnswersAsHtml(string $payload): void
    {
        foreach ([
            '/api/bookcase/search?q=' . rawurlencode($payload),
            '/api/bookcase/?latMin=' . rawurlencode($payload),
            '/api/v1/bookcases/' . rawurlencode(str_replace('/', '', $payload)),
            '/api/v1/bookcases?latMin=' . rawurlencode($payload),
        ] as $url) {
            $this->client->request('GET', $url);
            $response = $this->client->getResponse();
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $url);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
            $this->assertStringNotContainsString($payload, (string) $response->getContent(), "$url echoes the raw payload");
        }
    }
}
