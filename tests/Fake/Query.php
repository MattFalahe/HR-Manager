<?php

namespace HrManager\Tests\Fake;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * As much of Laravel's query builder as HR's reads use, over Fake\Rows.
 *
 * Only the call shapes the code under test actually writes are supported. A
 * shape it does not support throws rather than quietly answering wrongly,
 * because a fake that invents an answer turns a test into a rubber stamp.
 */
final class Query
{
    private string $table;
    private array $wheres = [];
    private array $groupBy = [];
    private ?string $selectRaw = null;
    private bool $distinct = false;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function where($column, $operator = null, $value = null): self
    {
        // Closure wheres are nested groups and the reads under test have none.
        // Checked with instanceof rather than is_callable, which is true for
        // 'date' (a PHP function) and would silently drop that clause.
        if ($column instanceof \Closure) {
            throw new \RuntimeException('Fake\Query does not support a closure where.');
        }

        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = ['kind' => 'compare', 'column' => $column, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    public function whereIn(string $column, $values): self
    {
        $this->wheres[] = [
            'kind'   => 'in',
            'column' => $column,
            'value'  => $values instanceof Collection ? $values->all() : (array) $values,
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['kind' => 'null', 'column' => $column];

        return $this;
    }

    public function groupBy(...$columns): self
    {
        $this->groupBy = $columns;

        return $this;
    }

    public function selectRaw(string $raw): self
    {
        $this->selectRaw = $raw;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * A join is a no-op: the fixtures are one flat row set per table, and a
     * joined read is written against whichever table carries the columns.
     */
    public function join(...$arguments): self
    {
        return $this;
    }

    public function get($columns = ['*']): Collection
    {
        $this->recordRead($this->groupBy ? 'grouped' : 'rows');

        $rows = $this->matching();

        if (empty($this->groupBy)) {
            return new Collection(array_map(fn (array $row) => (object) $row, $rows));
        }

        return new Collection($this->aggregate($rows));
    }

    public function max(string $column)
    {
        $this->recordRead('max ' . $column);

        $best = null;
        foreach ($this->matching() as $row) {
            $value = $row[$this->bare($column)] ?? null;
            if ($value !== null && ($best === null || (string) $value > (string) $best)) {
                $best = $value;
            }
        }

        return $best;
    }

    public function pluck(string $column, ?string $key = null): Collection
    {
        $this->recordRead('pluck ' . $column);

        $column = $this->bare($column);
        $out = [];
        foreach ($this->matching() as $row) {
            if ($key !== null) {
                $out[$row[$this->bare($key)]] = $row[$column] ?? null;
            } else {
                $out[] = $row[$column] ?? null;
            }
        }

        $plucked = new Collection($out);

        return $this->distinct ? $plucked->unique()->values() : $plucked;
    }

    public function count(): int
    {
        $this->recordRead('count');

        return count($this->matching());
    }

    public function exists(): bool
    {
        $this->recordRead('exists');

        return $this->matching() !== [];
    }

    public function first($columns = ['*'])
    {
        $this->recordRead('first');

        $rows = $this->matching();

        return $rows === [] ? null : (object) $rows[0];
    }

    // -----------------------------------------------------------------

    private function recordRead(string $kind): void
    {
        Rows::$reads[] = $this->table . ' [' . $kind . ']';
    }

    /** A joined read qualifies its columns; the fixture holds them bare. */
    private function bare(string $column): string
    {
        $at = strrpos($column, '.');

        return $at === false ? $column : substr($column, $at + 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function matching(): array
    {
        $out = [];

        foreach (Rows::all($this->table) as $row) {
            foreach ($this->wheres as $where) {
                if (!$this->passes($row, $where)) {
                    continue 2;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private function passes(array $row, array $where): bool
    {
        $have = $row[$this->bare($where['column'])] ?? null;

        if ($where['kind'] === 'null') {
            return $have === null;
        }

        if ($where['kind'] === 'in') {
            foreach ($where['value'] as $candidate) {
                if ((string) $have === (string) $candidate) {
                    return true;
                }
            }

            return false;
        }

        $want = $where['value'] instanceof Carbon
            ? $where['value']->toDateTimeString()
            : (string) $where['value'];

        return match ($where['operator']) {
            '='  => (string) $have === $want,
            '>=' => (string) $have >= $want,
            '>'  => (string) $have > $want,
            '<=' => (string) $have <= $want,
            '<'  => (string) $have < $want,
            default => throw new \RuntimeException('Fake\Query does not support the operator ' . $where['operator']),
        };
    }

    /**
     * Grouped aggregate. The aliases come out of the selectRaw the caller
     * wrote, which is how a real driver would label them, so a renamed alias
     * shows up as a failing test rather than a silently empty column.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, object>
     */
    private function aggregate(array $rows): array
    {
        $raw = (string) $this->selectRaw;

        if (!preg_match('/MAX\(`?([a-z_]+)`?\)/i', $raw, $found)) {
            throw new \RuntimeException('Fake\Query only aggregates MAX(): ' . $raw);
        }
        $dateColumn = $found[1];
        $keyColumn = $this->bare($this->groupBy[count($this->groupBy) - 1]);

        $newest = [];
        foreach ($rows as $row) {
            $bucket = implode('|', array_map(fn ($c) => (string) ($row[$this->bare($c)] ?? ''), $this->groupBy));
            if (!isset($newest[$bucket]) || (string) $row[$dateColumn] > (string) $newest[$bucket][$dateColumn]) {
                $newest[$bucket] = $row;
            }
        }

        $out = [];
        foreach ($newest as $row) {
            $record = new \stdClass();
            if (str_contains($raw, 'AS char_key')) {
                $record->char_key = $row[$keyColumn];
            }
            if (str_contains($raw, 'AS corp_key')) {
                $record->corp_key = $row['corporation_id'];
            }
            if (str_contains($raw, 'AS last_seen')) {
                $record->last_seen = $row[$dateColumn];
            }
            $out[] = $record;
        }

        return $out;
    }
}
