<?php declare(strict_types=1);

namespace App\Tests\Unit\Enums;

use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Enums\MessageType;
use App\Enums\NotificationChannel;
use App\Enums\WishlistItemStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function testAccessibilityLevelIsIntBackedForLegacyColumn(): void
    {
        $this->assertSame(1, AccessibilityLevel::None->value);
        $this->assertSame(2, AccessibilityLevel::Partial->value);
        $this->assertSame(3, AccessibilityLevel::Full->value);
    }

    /**
     * @return array<string, array{AccessibilityLevel, string, string, string}>
     */
    public static function accessibilityProvider(): array
    {
        return [
            'none'    => [AccessibilityLevel::None, 'error', 'none', 'red'],
            'partial' => [AccessibilityLevel::Partial, 'warning', 'partial', 'yellow'],
            'full'    => [AccessibilityLevel::Full, 'success', 'full', 'green'],
        ];
    }

    #[DataProvider('accessibilityProvider')]
    public function testAccessibilityLevelMappings(AccessibilityLevel $level, string $color, string $labelKey, string $markerColor): void
    {
        $this->assertSame($color, $level->color());
        $this->assertSame($labelKey, $level->labelKey());
        $this->assertSame($markerColor, $level->markerColor());
    }

    public function testActiveStatusValues(): void
    {
        $this->assertSame('active', ActiveStatus::Active->value);
        $this->assertSame('inactive', ActiveStatus::Inactive->value);
    }

    public function testEntryTypeAndMapSymbolValues(): void
    {
        $this->assertSame('bookcase', EntryType::Bookcase->value);
        $this->assertSame('givebox', EntryType::Givebox->value);
        $this->assertSame('standard', MapSymbol::Standard->value);
        $this->assertSame('givebox', MapSymbol::Givebox->value);
        $this->assertSame('tardis', MapSymbol::Tardis->value);
    }

    /**
     * @return array<string, array{WishlistItemStatus, string, string}>
     */
    public static function wishlistProvider(): array
    {
        return [
            'open'      => [WishlistItemStatus::Open, 'Open', 'badge-info'],
            'dropped'   => [WishlistItemStatus::Dropped, 'Dropped', 'badge-warning'],
            'not_found' => [WishlistItemStatus::NotFound, 'Not found', 'badge-error'],
            'fulfilled' => [WishlistItemStatus::Fulfilled, 'Fulfilled', 'badge-success'],
        ];
    }

    #[DataProvider('wishlistProvider')]
    public function testWishlistItemStatus(WishlistItemStatus $status, string $label, string $badge): void
    {
        $this->assertSame($label, $status->label());
        $this->assertSame($badge, $status->badgeClass());
    }

    public function testMessageTypeIcons(): void
    {
        $this->assertSame('megaphone', MessageType::Update->icon());
        $this->assertSame('map', MessageType::BookcaseChanged->icon());
        $this->assertSame('heart', MessageType::WishlistMatch->icon());
    }

    /**
     * @return array<string, array{NotificationChannel, bool, bool}>
     */
    public static function channelProvider(): array
    {
        return [
            'internal' => [NotificationChannel::Internal, true, false],
            'email'    => [NotificationChannel::Email, false, true],
            'both'     => [NotificationChannel::Both, true, true],
        ];
    }

    #[DataProvider('channelProvider')]
    public function testNotificationChannelDelivery(NotificationChannel $channel, bool $internal, bool $email): void
    {
        $this->assertSame($internal, $channel->deliversInternal());
        $this->assertSame($email, $channel->deliversEmail());
    }
}
