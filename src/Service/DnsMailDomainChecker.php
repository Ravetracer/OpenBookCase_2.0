<?php declare(strict_types=1);

namespace App\Service;

/**
 * DNS-based mail domain check (RFC 5321 §5.1): a domain accepts mail when it
 * publishes a usable MX record, or — without any MX — an A/AAAA record (implicit
 * MX). A "null MX" (RFC 7505, single MX with target ".") explicitly refuses mail.
 *
 * Lookup errors (resolver unreachable, timeouts) fail open: a temporary DNS
 * problem on our side must not block sign-ups. Only definitive empty answers
 * (NXDOMAIN, no records) reject the domain.
 */
class DnsMailDomainChecker implements MailDomainCheckerInterface
{
    /** RFC 1123 hostname with at least two labels; ≤ 253 chars, labels ≤ 63, no leading/trailing hyphen. */
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** @var array<string, bool> per-request cache, keyed by ASCII domain */
    private array $cache = [];

    public function acceptsMail(string $domain): bool
    {
        $domain = $this->normalize($domain);
        if ($domain === null) {
            return false;
        }
        if (!preg_match('/^[\x20-\x7e]*$/', $domain)) {
            // IDN that could not be converted to punycode (no intl): don't guess.
            return true;
        }
        // Never hand arbitrary input to the resolver (a NUL byte even throws a ValueError).
        if (!preg_match(self::HOSTNAME, $domain)) {
            return false;
        }

        return $this->cache[$domain] ??= $this->resolve($domain);
    }

    /**
     * @return list<array<string, mixed>>|false the records, or false on a lookup error
     */
    protected function lookup(string $domain, int $type): array|false
    {
        return @dns_get_record($domain, $type);
    }

    private function resolve(string $domain): bool
    {
        $mx = $this->lookup($domain, DNS_MX);
        if ($mx === false) {
            return true;
        }
        if ($mx !== []) {
            // A null MX is the only record with an empty target ("." is returned as "").
            foreach ($mx as $record) {
                if (trim((string) ($record['target'] ?? ''), '.') !== '') {
                    return true;
                }
            }

            return false;
        }

        $address = $this->lookup($domain, DNS_A | DNS_AAAA);

        return $address === false || $address !== [];
    }

    /** Lower-case, strip a trailing root dot, convert IDN to punycode (when intl is available); null when unusable. */
    private function normalize(string $domain): ?string
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        if ($domain === '' || !str_contains($domain, '.')) {
            return null;
        }
        if (str_contains($domain, "\0")) {
            return null;
        }
        if (!preg_match('/^[\x20-\x7e]*$/', $domain) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $domain = $ascii;
        }

        return $domain;
    }
}
