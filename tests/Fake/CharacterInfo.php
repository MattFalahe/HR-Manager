<?php

namespace HrManager\Tests\Fake;

/**
 * Stands in for SeAT's CharacterInfo. summarizeAlt() type-hints this class, so
 * reads have to hand back instances of it rather than plain objects.
 */
final class CharacterInfo extends Model
{
    protected static string $table = 'character_infos';
    protected static string $key = 'character_id';

    public $character_id;
    public $name;
    public $security_status;
    public $total_sp;

    public static function whereIn(string $column, $values): object
    {
        return new class (static::$table, $column, $values) {
            private Query $query;

            public function __construct(string $table, string $column, $values)
            {
                $this->query = (new Query($table))->whereIn($column, $values);
            }

            public function orderBy(string $column, string $direction = 'asc'): self
            {
                return $this;
            }

            public function get($columns = ['*']): EloquentCollection
            {
                $models = [];
                foreach ($this->query->get() as $row) {
                    $model = new CharacterInfo();
                    $model->character_id    = (int) $row->character_id;
                    $model->name            = $row->name ?? null;
                    $model->security_status = $row->security_status ?? null;
                    $model->total_sp        = $row->total_sp ?? null;
                    $models[] = $model;
                }

                usort($models, fn ($a, $b) => strcmp((string) $a->name, (string) $b->name));

                return new EloquentCollection($models);
            }
        };
    }
}
