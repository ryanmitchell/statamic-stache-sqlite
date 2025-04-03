<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Illuminate\Support\Str;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Entry;
use Statamic\Query\EloquentQueryBuilder;
use Thoughtco\StatamicStacheSqlite\Models\Entry as EntryModel;

class EntryQueryBuilder extends EloquentQueryBuilder implements QueryBuilder
{
    protected function column($column)
    {
        if (! is_string($column)) {
            return $column;
        }

        $table = Str::contains($column, '.') ? Str::before($column, '.') : '';
        $column = Str::after($column, '.');

        $columns = (new EntryModel)->resolveConnection()->getSchemaBuilder()->getColumnListing((new EntryModel)->getTable());

        if (! in_array($column, $columns)) {
            if (! Str::startsWith($column, 'data->')) {
                $column = 'data->'.$column;
            }
        }

        return ($table ? $table.'.' : '').$column;
    }

    protected function transform($items, $columns = [])
    {
        $items = EntryCollection::make($items)->map(function ($model) use ($columns) {
            return $model->makeContract()
                ->model($model)
                ->selectedQueryColumns($this->selectedQueryColumns ?? $columns);
        });

        return Entry::applySubstitutions($items);
    }
}
