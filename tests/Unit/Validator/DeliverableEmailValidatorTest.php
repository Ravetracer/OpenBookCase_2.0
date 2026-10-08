<?php declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Tests\Double\FakeMailDomainChecker;
use App\Validator\DeliverableEmail;
use App\Validator\DeliverableEmailValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<DeliverableEmailValidator> */
final class DeliverableEmailValidatorTest extends ConstraintValidatorTestCase
{
    #[DataProvider('ignoredValues')]
    public function testIgnoredValuesRaiseNoViolation(?string $value): void
    {
        $this->validator->validate($value, new DeliverableEmail());

        $this->assertNoViolation();
    }

    public static function ignoredValues(): iterable
    {
        yield 'null (NotBlank handles it)' => [null];
        yield 'empty string' => [''];
        yield 'no @ (Email handles it)' => ['not-an-email'];
        yield 'trailing @ (Email handles it)' => ['someone@'];
        yield 'deliverable domain' => ['reader@example.org'];
    }

    public function testUndeliverableDomainIsRejected(): void
    {
        $this->validator->validate('reader@nowhere.invalid', new DeliverableEmail());

        $this->buildViolation('user.email_undeliverable')
            ->setInvalidValue('reader@nowhere.invalid')
            ->assertRaised();
    }

    public function testDomainIsTakenAfterTheLastAt(): void
    {
        // A quoted local part may itself contain "@".
        $this->validator->validate('"a@example.org"@nowhere.invalid', new DeliverableEmail());

        $this->buildViolation('user.email_undeliverable')
            ->setInvalidValue('"a@example.org"@nowhere.invalid')
            ->assertRaised();
    }

    public function testCustomMessage(): void
    {
        $this->validator->validate('reader@nowhere.invalid', new DeliverableEmail(message: 'custom'));

        $this->buildViolation('custom')->setInvalidValue('reader@nowhere.invalid')->assertRaised();
    }

    public function testNonStringValueIsRejectedAsUnexpected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->validator->validate(['reader@example.org'], new DeliverableEmail());
    }

    public function testWrongConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);
        $this->validator->validate('reader@example.org', new NotBlank());
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        return new DeliverableEmailValidator(new FakeMailDomainChecker());
    }
}
