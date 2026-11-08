<?php

namespace HrManager\Tests\Fake;

/**
 * The static entry points HR calls on SeAT's models, answered from Fake\Rows.
 * Each subclass names its table and its key.
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $key = 'id';

    public static function query(): Query
    {
        return new Query(static::$table);
    }

    public static function where($column, $operator = null, $value = null): Query
    {
        return (new Query(static::$table))->where($column, $operator, $value);
    }

    /**
     * Not typed to Query: a model whose reads have to hand back real model
     * instances returns its own reader instead, and narrowing the type here
     * would make that subclass a fatal at class-load.
     */
    public static function whereIn(string $column, $values): object
    {
        return (new Query(static::$table))->whereIn($column, $values);
    }

    public static function whereNull(string $column): Query
    {
        return (new Query(static::$table))->whereNull($column);
    }

    public static function find($id)
    {
        Rows::$reads[] = static::$table . ' [find]';

        foreach (Rows::all(static::$table) as $row) {
            if ((string) ($row[static::$key] ?? null) === (string) $id) {
                return (object) $row;
            }
        }

        return null;
    }
}
