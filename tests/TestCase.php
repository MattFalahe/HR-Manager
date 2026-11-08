<?php

namespace HrManager\Tests;

use Carbon\Carbon;
use HrManager\Tests\Fake\Cache;
use HrManager\Tests\Fake\Container;
use HrManager\Tests\Fake\Rows;
use HrManager\Tests\Fake\Schema;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Every "days since" figure in HR is measured from now, so the clock is
     * fixed or the same fixture would classify differently depending on when
     * the suite ran.
     */
    protected const NOW = '2026-11-08 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
        Rows::reset();
        Cache::reset();
        Schema::reset();
        Container::reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
