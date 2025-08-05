<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Stache\Query\QueriesEntryStatus;
use Thoughtco\StatamicStacheSqlite\Models\Entry as EntryModel;

class EntryQueryBuilder extends EloquentQueryBuilder implements QueryBuilder
{
    use QueriesEntryStatus;

    protected function column($column)
    {
        if (! is_string($column)) {
            return $column;
        }

        $table = Str::contains($column, '.') ? Str::before($column, '.') : '';
        $column = Str::after($column, '.');

        $columns = Blink::once('entry-columns', fn () => (new EntryModel)->resolveConnection()->getSchemaBuilder()->getColumnListing((new EntryModel)->getTable()));

        if (! in_array($column, $columns)) {
            if (! Str::startsWith($column, 'values->')) {
                $column = 'values->'.$column;
            }
        }

        return ($table ? $table.'.' : '').$column;
    }

    private function ensureCollectionsAreQueriedForStatusQuery(): void
    {
        $wheres = collect($this->builder->getQuery()->wheres);

        // If there are where clauses on the collection column, it means the user has explicitly
        // queried for them. In that case, we'll use them and skip the auto-detection.
        if ($wheres->where('column', 'collection')->isNotEmpty()) {
            return;
        }

        // Otherwise, we'll detect them by looking at where clauses targeting the "id" column.
        $ids = $wheres->where('column', 'id')->flatMap(fn ($where) => $where['values'] ?? [$where['value']]);

        // If no IDs were queried, fall back to all collections.
        $ids->isEmpty()
            ? $this->whereIn('collection', Collection::handles())
            : $this->whereIn('collection', app(static::class)->whereIn('id', $ids)->pluck('collection')->unique()->values());
    }

    public function find($id, $columns = ['*'])
    {
        if ($result = Blink::once("entry-{$id}", fn () => parent::find($id))) {
            return $result->selectedQueryColumns($columns);
        }

        Blink::forget("entry-{$id}");

        return false;
    }

    private function getCollectionsForStatusQuery(): \Illuminate\Support\Collection
    {
        // Since we have to add nested queries for each collection, we only want to add clauses for the
        // applicable collections. By this point, there should be where clauses on the collection column.

        return collect($this->builder->getQuery()->wheres)
            ->where('column', 'collection')
            ->flatMap(fn ($where) => $where['values'] ?? [$where['value']])
            ->map(fn ($handle) => Collection::find($handle));
    }

    protected function transform($items, $columns = [])
    {
        $items = EntryCollection::make($items)->map(function ($model) use ($columns) {
            return Blink::once("entry-{$model->id}", function () use ($model) {
                return $model->makeContract();
            })->selectedQueryColumns($this->selectedQueryColumns ?? $columns);
        });

        return Entry::applySubstitutions($items);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column === 'status') {
            trigger_error('Filtering by status is deprecated. Use whereStatus() instead.', E_USER_DEPRECATED);
        }

        return parent::where(...func_get_args());
    }

    public function whereIn($column, $values, $boolean = 'and')
    {
        if ($column === 'status') {
            trigger_error('Filtering by status is deprecated. Use whereStatus() instead.', E_USER_DEPRECATED);
        }

        return parent::whereIn(...func_get_args());
    }

    // @TODO: this has been raised as a PR to core, once merged it can be removed
    public function whereTime($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        // ensure value is h:m:s
        if (! ($value instanceof DateTimeInterface)) {
            $value = Carbon::parse($value);
        }

        $value = $value->format('H:i:s'); // we only care about the time part

        parent::whereTime($column, $operator, $value, $boolean);

        return $this;
    }
}
