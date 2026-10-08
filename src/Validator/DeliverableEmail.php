<?php declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The e-mail address's domain must be able to receive mail (MX or A/AAAA
 * record, no null MX). Complements the syntax-only Email constraint; it cannot
 * prove that the mailbox itself exists — the verification link does that.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class DeliverableEmail extends Constraint
{
    public function __construct(
        public string $message = 'user.email_undeliverable',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
