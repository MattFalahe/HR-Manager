<?php

namespace HrManager\Tests;

/**
 * A read that failed is logged and the caller carries on. There is nowhere to
 * log to here, and a test that cares records the failure through Fake\Rows
 * instead.
 */
final class NoLog
{
    public static function __callStatic(string $name, array $arguments): void
    {
    }
}
