<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Service\ApiInput;
use App\Service\BookcaseApiMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BookcaseApiMapperTest extends TestCase
{
    public function testFromCreatePayloadMapsFields(): void
    {
        $bookcase = $this->mapper()->fromCreatePayload([
            'title' => '  Corner box ',
            'entryType' => 'givebox',
            'installationType' => 'Phone booth',
            'isBookcrossingZone' => true,
            'latitude' => '52.5',
            'longitude' => 13.4,
        ]);

        $this->assertSame('Corner box', $bookcase->title);
        $this->assertSame(EntryType::Givebox, $bookcase->entryType);
        $this->assertSame('Phone booth', $bookcase->installationType);
        $this->assertTrue($bookcase->isBookcrossingZone);
        $this->assertSame(52.5, $bookcase->position->latitude);
        $this->assertSame(13.4, $bookcase->position->longitude);
    }

    public function testFromCreatePayloadWithoutCoordinatesLeavesThemNull(): void
    {
        $bookcase = $this->mapper()->fromCreatePayload([]);

        $this->assertNull($bookcase->title);
        $this->assertNull($bookcase->position->latitude);
        $this->assertFalse($bookcase->isBookcrossingZone);
    }

    public function testApplyPatchOnlyChangesPresentKeys(): void
    {
        $bookcase = new Bookcase();
        $bookcase->title = 'Old';
        $bookcase->titleProvisional = true;
        $bookcase->comment = 'keep me';

        $this->mapper()->applyPatch($bookcase, [
            'title' => 'New',
            'activeStatus' => 'inactive',
            'accessibilityLevel' => 3,
            'address' => ['city' => 'Berlin', 'street' => ''],
        ]);

        $this->assertSame('New', $bookcase->title);
        $this->assertFalse($bookcase->titleProvisional);
        $this->assertSame('keep me', $bookcase->comment);
        $this->assertSame(ActiveStatus::Inactive, $bookcase->active->status);
        $this->assertSame(AccessibilityLevel::Full, $bookcase->accessibility->level);
        $this->assertSame('Berlin', $bookcase->address->city);
        $this->assertNull($bookcase->address->street);

        $this->mapper()->applyPatch($bookcase, ['accessibilityLevel' => null]);
        $this->assertNull($bookcase->accessibility->level);
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('invalidPatches')]
    public function testInvalidEnumValuesAreRejected(array $patch, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);

        $this->mapper()->applyPatch(new Bookcase(), $patch);
    }

    public static function invalidPatches(): iterable
    {
        yield 'entryType' => [['entryType' => 'castle'], 'Invalid entryType.'];
        yield 'activeStatus' => [['activeStatus' => 'maybe'], 'Invalid activeStatus'];
        yield 'accessibility out of range' => [['accessibilityLevel' => 7], 'Invalid accessibilityLevel'];
        yield 'accessibility as string' => [['accessibilityLevel' => '2'], 'Invalid accessibilityLevel'];
    }

    public function testInvalidEntryTypeOnCreateIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid entryType (expected "bookcase" or "givebox").');

        $this->mapper()->fromCreatePayload(['entryType' => 'castle']);
    }

    private function mapper(): BookcaseApiMapper
    {
        return new BookcaseApiMapper(new ApiInput($this->createStub(ValidatorInterface::class)));
    }
}
