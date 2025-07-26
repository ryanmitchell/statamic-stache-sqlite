<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Entries\QueryBuilder;
use ThoughtCo\StatamicStacheSqlite\Models\Entry as EntryModel;

class EntryRepository extends \Statamic\Stache\Repositories\EntryRepository
{
    public static function bindings(): array
    {
        return [
            EntryContract::class => Entry::class,
            QueryBuilder::class => EntryQueryBuilder::class,
        ];
    }

    public function save($entry)
    {
        $model = $entry->model() ?? EntryModel::find($entry->id()) ?? EntryModel::make();

        $model
            ->fromContract($entry)
            ->save();

        $entry->model($model);
    }

    public function delete($entry)
    {
        $model = $entry->model() ?? EntryModel::find($entry->id()) ?? EntryModel::make();

        $model
            ->fromContract($entry)
            ->delete();
    }
}
