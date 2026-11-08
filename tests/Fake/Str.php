<?php

namespace HrManager\Tests\Fake;

/** The one method HR uses off Illuminate\Support\Str. */
final class Str
{
    public static function uuid(): object
    {
        return new class {
            public function toString(): string
            {
                return '00000000-0000-0000-0000-000000000000';
            }

            public function __toString(): string
            {
                return $this->toString();
            }
        };
    }
}
