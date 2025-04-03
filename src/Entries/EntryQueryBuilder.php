<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Entry;
use Statamic\Query\EloquentQueryBuilder;

class EntryQueryBuilder extends EloquentQueryBuilder implements QueryBuilder
{
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
