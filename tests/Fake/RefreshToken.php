<?php

namespace HrManager\Tests\Fake;

/**
 * Stands in for SeAT's RefreshToken. The real model soft-deletes, and HR relies
 * on the difference: the Eloquent reads see live tokens only, while a
 * DB::table() read sees revoked ones too. The fixtures carry deleted_at and the
 * tests exercise both paths.
 */
final class RefreshToken extends Model
{
    protected static string $table = 'refresh_tokens';
    protected static string $key = 'character_id';
}
