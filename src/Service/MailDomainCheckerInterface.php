<?php declare(strict_types=1);

namespace App\Service;

/**
 * Decides whether a mail domain can receive e-mail at all, so verification mails
 * are not sent to addresses that would bounce back to the sender.
 */
interface MailDomainCheckerInterface
{
    /** @param string $domain the part after the "@" (ASCII/punycode or Unicode) */
    public function acceptsMail(string $domain): bool;
}
