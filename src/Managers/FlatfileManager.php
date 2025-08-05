<?php

namespace Thoughtco\StatamicStacheSqlite\Managers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Manager;
use Thoughtco\StatamicStacheSqlite\Models\Asset;
use Thoughtco\StatamicStacheSqlite\Models\Entry;

class FlatfileManager extends Manager
{
    protected $connection = null;

    public function getDefaultDriver()
    {
        return 'stache';
    }

    public function getDatabaseName()
    {
        return 'statamic';
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
        if ($datetime) {
            cache()->forever('statamic_stache_updated_at', $datetime);
        }

        // Default to a very old date if the cache is not set
        return cache()->get('statamic_stache_updated_at', now()->subCenturies(100));
    }

    public function clear()
    {
        if (! empty($this->connection()->getSchemaBuilder()->getTables())) {
            // Disconnect the connection to avoid issues with dropping tables
            $this->connection()->disconnect();
            $this->connection()->getSchemaBuilder()->dropAllTables();
        }
    }

    public function warm()
    {
        Asset::bootStoreAsFlatfile();
        Entry::bootStoreAsFlatfile();
    }
}
