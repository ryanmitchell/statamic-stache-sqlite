<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Statamic\Entries\Entry as FileEntry;
use ThoughtCo\StatamicStacheSqlite\Models\Entry as EntryModel;

class Entry extends FileEntry
{
    private ?EntryModel $model = null;

    public function model($model = null)
    {
        if (! $model) {
            return $this->model;
        }

        $this->model = $model;

        return $this;
    }
}
