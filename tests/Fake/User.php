<?php

namespace HrManager\Tests\Fake;

final class User extends Model
{
    protected static string $table = 'users';
    protected static string $key = 'id';

    public $id;
    public $main_character_id;

    public static function find($id)
    {
        $row = parent::find($id);
        if ($row === null) {
            return null;
        }

        $user = new User();
        $user->id = (int) $row->id;
        $user->main_character_id = $row->main_character_id ?? null;

        return $user;
    }
}
