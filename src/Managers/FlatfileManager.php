<?php

namespace Thoughtco\StatamicStacheSqlite\Managers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Manager;
use Thoughtco\StatamicStacheSqlite\Models\Asset;
use Thoughtco\StatamicStacheSqlite\Models\Entry;

class FlatfileManager extends Manager
{
    protected $connection = null;

    protected bool $isMigrating = false;

    public function getDefaultDriver()
    {
        return 'stache';
    }

    public function getDatabaseName()
    {
        return 'statamic';
    }

    public function isMigrating(?bool $value = null)
    {
        if (count(func_get_args()) == 0) {
            return $this->isMigrating;
        }

        $this->isMigrating = $value;

        return $this->isMigrating;
    }

    public function connection($connection = null)
    {
        if ($connection) {
            $this->connection = $connection;
        } else {
            $this->connection ??= DB::connection($this->getDatabaseName());
        }

        return $this->connection;
    }

    public function databaseUpdatedAt($datetime = null)
    {
        $key = 'statamic::flatfile_updated_at';

        if ($datetime) {
            cache()->forever($key, $datetime);

            return $this;
        }

        // Default to a very old date if the cache is not set
        return cache()->get($key, now()->subCenturies(100));
    }

    public function clear()
    {
        foreach ([Asset::class, Entry::class] as $model) {
            $model::$runMigrationsIfNecessary = false;
            $this->connection()->getSchemaBuilder()->dropIfExists((new $model)->getTable());
            $model::$runMigrationsIfNecessary = true;
        }
    }

    public function warm()
    {
        Asset::bootStoreAsFlatfile();
        Entry::bootStoreAsFlatfile();
    }
}
