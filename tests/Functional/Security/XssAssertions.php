<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

/**
 * Shared payloads and assertions for the XSS / output-escaping tests.
 *
 * An HTML response is considered safe for a payload when (a) the raw payload
 * never appears verbatim, (b) the payload never materialises as a live DOM
 * construct (injected <script>, an on* handler, a dangerous-scheme URL) and,
 * optionally, (c) the payload is present as decoded text/attribute data — proving
 * the value really reached the page, so (a) does not pass merely because nothing
 * was rendered.
 */
trait XssAssertions
{
    /** Payloads aimed at element, attribute (both quote styles) and RCDATA contexts. */
    public static function htmlPayloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'attribute breakout + img onerror' => ['"><img src=x onerror=alert(1)>'];
        yield 'single-quote attribute breakout' => ["' onmouseover='alert(1)"];
        yield 'textarea breakout + svg onload' => ['</textarea><svg onload=alert(1)>'];
    }

    /** URL values that execute script (or render attacker HTML) when used as a link target. */
    public static function dangerousUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'javascript leading whitespace' => ['  javascript:alert(1)'];
        yield 'data html' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
    }

    /** Assert the payload is present only as inert, escaped text in an HTML body. */
    protected function assertPayloadEscaped(string $html, string $payload, bool $expectEscapedForm = true): void
    {
        $this->assertStringNotContainsString($payload, $html, 'raw (unescaped) payload found in the HTML output');
        $xpath = $this->assertNoLiveXss($html);

        if ($expectEscapedForm) {
            // The payload must survive as decoded text or attribute data (whatever the
            // escaping strategy, html or html_attr) — otherwise the field was never
            // rendered and the checks above prove nothing.
            $found = false;
            foreach ($xpath->query('//text() | //@*') as $node) {
                if (str_contains((string) $node->nodeValue, $payload)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'payload not found as text/attribute data — the field was not rendered, so the test proves nothing');
        }
    }

    /**
     * DOM-level check: nothing injected became executable. Page-own handlers
     * (e.g. onclick="this.select()") are fine; only payload markers are flagged.
     */
    protected function assertNoLiveXss(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//script') as $script) {
            $this->assertStringNotContainsString('alert(1)', $script->textContent, 'injected <script> executed payload');
        }

        foreach ($xpath->query('//@*') as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;

            if (str_starts_with($name, 'on')) {
                $this->assertDoesNotMatchRegularExpression(
                    '/alert\(1\)|msgbox\(1\)/i',
                    $value,
                    sprintf('injected event handler <%s %s="%s">', $attr->ownerElement?->nodeName, $name, $value),
                );
            }

            if (\in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster'], true)) {
                // Browsers ignore leading whitespace/control chars and embedded tabs/newlines in the scheme.
                $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? '');
                $this->assertDoesNotMatchRegularExpression(
                    '/^(javascript|vbscript|data):/',
                    $normalised,
                    sprintf('dangerous URL scheme emitted in <%s %s="%s">', $attr->ownerElement?->nodeName, $name, $value),
                );
            }
        }

        // Elements that only exist when a payload became markup.
        $this->assertSame(0, $xpath->query('//img[@src="x"]')->length, 'injected <img src=x> element');
        $this->assertSame(0, $xpath->query('//svg[@onload] | //*[local-name()="svg"][@onload]')->length, 'injected <svg onload>');

        return $xpath;
    }

    /** JSON endpoints: served as JSON (never sniffable as HTML) and carrying the payload as plain data. */
    protected function assertJsonCarriesPayload(string $body, ?string $contentType, string $payload): void
    {
        $this->assertNotNull($contentType);
        $this->assertStringStartsWith('application/json', $contentType, 'JSON endpoint must not be served as HTML');
        $this->assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $data = json_decode($body, true);
        $this->assertIsArray($data, 'response is not valid JSON');

        $strings = [];
        array_walk_recursive($data, static function ($v) use (&$strings): void {
            if (\is_string($v)) {
                $strings[] = $v;
            }
        });
        $this->assertContains($payload, $strings, 'payload not found as a JSON string value');
    }
}
