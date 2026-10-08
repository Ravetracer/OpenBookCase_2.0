<?php declare(strict_types=1);

namespace App\Validator;

use App\Service\MailDomainCheckerInterface;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class DeliverableEmailValidator extends ConstraintValidator
{
    public function __construct(private readonly MailDomainCheckerInterface $domains)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DeliverableEmail) {
            throw new UnexpectedTypeException($constraint, DeliverableEmail::class);
        }
        // Blank values are NotBlank's job.
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $at = strrpos($value, '@');
        // Malformed addresses are reported by the Email constraint; don't add a second error.
        if ($at === false || $at === strlen($value) - 1) {
            return;
        }

        if (!$this->domains->acceptsMail(substr($value, $at + 1))) {
            $this->context->buildViolation($constraint->message)
                ->setInvalidValue($value)
                ->addViolation();
        }
    }
}
