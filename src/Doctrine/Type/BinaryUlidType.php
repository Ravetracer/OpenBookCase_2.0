<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Ulid;

/**
 * ULID Doctrine type that binds values as binary.
 *
 * Symfony's built-in {@see \Symfony\Bridge\Doctrine\Types\UlidType} declares the
 * column as BLOB but inherits the default STRING binding type. On SQLite that
 * stores the 16 raw ULID bytes with TEXT storage class, which never compares
 * equal to a BLOB-bound parameter — so `WHERE id = :ulid` lookups silently miss
 * those rows (HTTP 404). Binding as BINARY stores and matches consistently as
 * BLOB, matching how the raw-DBAL import writes ids.
 */
final class BinaryUlidType extends AbstractUidType
{
    public const NAME = 'ulid';

    protected function getUidClass(): string
    {
        return Ulid::class;
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::BINARY;
    }
}
