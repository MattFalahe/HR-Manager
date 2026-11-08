<?php

namespace HrManager\Tests\Fake;

/**
 * The tables the tests pretend exist, and what is in them.
 *
 * A fake stands in for MySQL rather than a real database because the point of
 * these tests is the PHP: which sources a figure is taken from, which rows are
 * excluded and how many reads it costs. `$reads` is part of that: a read per
 * character and a read per batch both give the right answer, and the whole
 * reason the batched path exists is the difference between them.
 */
final class Rows
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $tables = [];

    /** @var array<int, string> every read the engine served, newest last */
    public static array $reads = [];

    public static function reset(): void
    {
        self::$tables = [];
        self::$reads = [];
    }

    /**
     * Declare a table and its rows. A table not declared here does not exist,
     * which is how the Schema::hasTable branches are exercised.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function table(string $name, array $rows = []): void
    {
        self::$tables[$name] = $rows;
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::$tables);
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $name): array
    {
        return self::$tables[$name] ?? [];
    }

    public static function countReads(): int
    {
        return count(self::$reads);
    }

    public static function forgetReads(): void
    {
        self::$reads = [];
    }
}
