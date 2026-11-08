<?php

namespace HrManager\Tests\Fake;

/**
 * Stands in for Illuminate\Support\Facades\Schema. A table exists only if a
 * test declared it through Fake\Rows, which is what exercises HR's
 * hasTable guards: an install missing a sibling plugin's table has to degrade,
 * not throw.
 */
final class Schema
{
    /** @var array<string, bool> keyed "table.column"; anything absent is present. */
    public static array $missingColumns = [];

    public static function hasTable(string $table): bool
    {
        return Rows::has($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return !isset(self::$missingColumns[$table . '.' . $column]);
    }

    public static function reset(): void
    {
        self::$missingColumns = [];
    }
}
