<?php

namespace HrManager\Tests\Fake;

/** Stands in for Illuminate\Support\Facades\DB. */
final class Db
{
    public static function table(string $name): Query
    {
        return new Query($name);
    }
}
