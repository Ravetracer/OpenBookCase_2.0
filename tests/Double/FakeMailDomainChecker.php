<?php declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\MailDomainCheckerInterface;

/**
 * Test-env stand-in for DnsMailDomainChecker: no network. Domains under the
 * reserved ".invalid" TLD (RFC 2606) are undeliverable, everything else accepts mail.
 */
final class FakeMailDomainChecker implements MailDomainCheckerInterface
{
    public function acceptsMail(string $domain): bool
    {
        return !str_ends_with(rtrim(strtolower($domain), '.'), '.invalid');
    }
}
