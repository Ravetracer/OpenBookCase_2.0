<?php declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Every response must carry the baseline hardening headers.
 * See App\EventSubscriber\SecurityHeadersSubscriber.
 */
final class SecurityHeadersTest extends FunctionalTestCase
{
    public function testHomepageCarriesSecurityHeaders(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $headers = $this->client->getResponse()->headers;

        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        $this->assertNotNull($headers->get('Permissions-Policy'));

        $csp = (string) $headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function testHstsAbsentOnPlainHttp(): void
    {
        // Dev/test runs over plain HTTP, where HSTS must not be emitted.
        $this->client->request('GET', '/');
        $this->assertFalse($this->client->getResponse()->headers->has('Strict-Transport-Security'));
    }
}
