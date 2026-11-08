<?php

namespace HrManager\Tests\Fake;

/** Stands in for Illuminate\Support\Facades\Cache. In memory, cleared per test. */
final class Cache
{
    /** @var array<string, mixed> */
    public static array $store = [];

    public static function get($key, $default = null)
    {
        return self::$store[$key] ?? $default;
    }

    public static function put($key, $value, $ttl = null): bool
    {
        self::$store[$key] = $value;

        return true;
    }

    public static function forget($key): bool
    {
        unset(self::$store[$key]);

        return true;
    }

    public static function remember($key, $ttl, $callback)
    {
        return self::$store[$key] ??= $callback();
    }

    public static function reset(): void
    {
        self::$store = [];
    }
}
