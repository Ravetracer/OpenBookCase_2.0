<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DnsMailDomainChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DnsMailDomainCheckerTest extends TestCase
{
    /**
     * @param array<string, list<array<string, mixed>>|false> $mx keyed by domain
     * @param array<string, list<array<string, mixed>>|false> $address keyed by domain
     */
    #[DataProvider('dnsAnswers')]
    public function testAcceptsMail(array $mx, array $address, string $domain, bool $expected): void
    {
        $this->assertSame($expected, $this->checker($mx, $address)->acceptsMail($domain));
    }

    public static function dnsAnswers(): iterable
    {
        $mx = [['host' => 'gmail.com', 'type' => 'MX', 'pri' => 5, 'target' => 'gmail-smtp-in.l.google.com']];
        $nullMx = [['host' => 'example.com', 'type' => 'MX', 'pri' => 0, 'target' => '']];
        $a = [['host' => 'smallhost.de', 'type' => 'A', 'ip' => '192.0.2.10']];

        yield 'usable MX' => [['gmail.com' => $mx], [], 'gmail.com', true];
        yield 'null MX (RFC 7505) refuses mail' => [['example.com' => $nullMx], [], 'example.com', false];
        yield 'null MX with a root dot target' => [['example.com' => [['type' => 'MX', 'pri' => 0, 'target' => '.']]], [], 'example.com', false];
        yield 'no MX, but A record (implicit MX)' => [['smallhost.de' => []], ['smallhost.de' => $a], 'smallhost.de', true];
        yield 'no MX, no address (NXDOMAIN / typo)' => [['gmial.cmo' => []], ['gmial.cmo' => []], 'gmial.cmo', false];
        yield 'MX lookup error fails open' => [['flaky.de' => false], [], 'flaky.de', true];
        yield 'address lookup error fails open' => [['flaky.de' => []], ['flaky.de' => false], 'flaky.de', true];
        yield 'case + trailing root dot are normalized' => [['gmail.com' => $mx], [], 'GMail.COM.', true];
        yield 'single label is never deliverable' => [[], [], 'localhost', false];
        yield 'empty domain' => [[], [], '', false];
        yield 'only a dot' => [[], [], '.', false];
    }

    /** Syntactically impossible hosts are rejected without ever reaching the resolver. */
    #[DataProvider('malformedDomains')]
    public function testMalformedDomainIsRejectedWithoutLookup(string $domain): void
    {
        $checker = $this->checker([], []);

        $this->assertFalse($checker->acceptsMail($domain));
        $this->assertSame(0, $checker->lookups);
    }

    public static function malformedDomains(): iterable
    {
        yield 'NUL byte' => ["evil\0.de"];
        yield 'label > 63 chars' => [str_repeat('a', 64) . '.de'];
        yield 'domain > 253 chars' => [implode('.', array_fill(0, 50, 'abcdef')) . '.de'];
        yield 'leading hyphen' => ['-evil.de'];
        yield 'empty label' => ['a..de'];
        yield 'space' => ['a b.de'];
        yield 'address literal' => ['[127.0.0.1]'];
        yield 'underscore' => ['my_host.de'];
        yield 'injection attempt' => ["example.com'; DROP TABLE user;--"];
    }

    public function testIdnDomainIsLookedUpAsPunycode(): void
    {
        $checker = $this->checker(['xn--mller-kva.de' => [['type' => 'MX', 'pri' => 10, 'target' => 'mx.xn--mller-kva.de']]], []);

        $this->assertTrue($checker->acceptsMail('müller.de'));
    }

    public function testResultIsCachedPerDomain(): void
    {
        $checker = $this->checker(['gmail.com' => [['type' => 'MX', 'pri' => 5, 'target' => 'mx.gmail.com']]], []);

        $checker->acceptsMail('gmail.com');
        $checker->acceptsMail('GMAIL.com');

        $this->assertSame(1, $checker->lookups);
    }

    /**
     * @param array<string, list<array<string, mixed>>|false> $mx
     * @param array<string, list<array<string, mixed>>|false> $address
     */
    private function checker(array $mx, array $address): DnsMailDomainChecker
    {
        return new class($mx, $address) extends DnsMailDomainChecker {
            public int $lookups = 0;

            public function __construct(private readonly array $mx, private readonly array $address)
            {
            }

            protected function lookup(string $domain, int $type): array|false
            {
                ++$this->lookups;

                return ($type === DNS_MX ? $this->mx : $this->address)[$domain] ?? [];
            }
        };
    }
}
