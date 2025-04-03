<?php

namespace Thoughtco\StatamicStacheSqlite\Entries;

use Statamic\Entries\Entry as FileEntry;
use ThoughtCo\StatamicStacheSqlite\Models\Entry as EntryModel;

class Entry extends FileEntry {

    private ?EntryModel $entry = null;

    public function model($entry = null)
    {
        if (! $entry) {
            return $this->entry;
        }

        $this->entry = $entry;

        return $this;
    }
}
