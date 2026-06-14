<?php declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\BinaryUlidType;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

/**
 * Guards the SQLite ULID gotcha: the binding type MUST be BINARY, otherwise
 * `WHERE id = :ulid` silently misses rows on SQLite (see the class docblock).
 */
final class BinaryUlidTypeTest extends TestCase
{
    public function testBindingTypeIsBinary(): void
    {
        $type = new BinaryUlidType();
        $this->assertSame(ParameterType::BINARY, $type->getBindingType());
    }

    public function testRegisteredUnderUlidName(): void
    {
        $this->assertSame('ulid', BinaryUlidType::NAME);
    }
}
