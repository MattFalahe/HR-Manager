<?php

namespace HrManager\Tests\Fake;

/**
 * What app() hands back. Nothing is bound unless a test binds it, so a service
 * reaching for something the test did not provide gets null and has to cope,
 * which is the same shape as a sibling plugin not being installed.
 */
final class Container
{
    /** @var array<string, object> */
    private static array $bindings = [];

    public static function bind(string $abstract, object $instance): void
    {
        self::$bindings[$abstract] = $instance;
    }

    public static function get($abstract)
    {
        return is_string($abstract) ? (self::$bindings[$abstract] ?? null) : null;
    }

    public static function reset(): void
    {
        self::$bindings = [];
    }
}
