<?php

namespace Thoughtco\StatamicStacheSqlite;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Statamic\Contracts\Assets\AssetRepository as AssetRepositoryContract;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;
use Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon()
    {
        Flatfile::connection()->listen(function (QueryExecuted $query) {
            if (Flatfile::isMigrating()) {
                return;
            }

            // If the query is an INSERT, UPDATE, or DELETE, we consider it a modification
            $updated = collect(['insert', 'update', 'delete'])
                ->reduce(fn ($carry, $type) => $carry || str_starts_with(strtolower($query->sql), $type), false);

            if ($updated) {
                Flatfile::databaseUpdatedAt(now());
            }
        });

        if (Flatfile::connection()->getDriverName() === 'sqlite') {
            $this->setupSqlite();
        }

        $this->registerAssetRepository()
            ->registerEntryRepository();
    }

    public function setupSqlite()
    {
        $connection = Flatfile::connection();
        $path = config('database.connections.'.Flatfile::getDatabaseName().'.database');
        $fs = new Filesystem;

        if (! $fs->exists($path) && $path !== ':memory:') {
            $fs->ensureDirectoryExists(dirname($path));
            $fs->put($path, '');
        }

        // thank you: https://github.com/nunomaduro/laravel-optimize-database/tree/main
        if (data_get($connection->select('PRAGMA journal_mode'), '0.journal_mode') != 'wal') {
            $connection->unprepared(<<<'SQL'
                PRAGMA auto_vacuum = incremental;
                PRAGMA journal_mode = WAL;
                PRAGMA page_size = 32768;
            SQL);
        }

        $connection->unprepared(<<<'SQL'
            PRAGMA busy_timeout = 5000;
            PRAGMA cache_size = -20000;
            PRAGMA foreign_keys = ON;
            PRAGMA incremental_vacuum;
            PRAGMA mmap_size = 2147483648;
            PRAGMA temp_store = MEMORY;
            PRAGMA synchronous = NORMAL;
        SQL);
    }

    public function register()
    {
        $this->app->scoped(FlatfileManager::class, function ($app) {
            $manager = new FlatfileManager($app);
            $manager->extend('stache', fn () => new Drivers\StacheDriver($app));

            return $manager;
        });

        $this->app['config']->set('database.connections.'.Flatfile::getDatabaseName(), [
            'driver' => 'sqlite',
            'database' => storage_path('statamic/cache/stache.sqlite'),
            'foreign_key_constraints' => false,
            ...config('database.connections.'.Flatfile::getDatabaseName(), []),
        ]);
    }

    private function registerEntryRepository()
    {
        Statamic::repository(
            abstract: EntryRepositoryContract::class,
            concrete: Entries\EntryRepository::class
        );

        $this->app->bind(
            Entries\EntryQueryBuilder::class,
            function ($app) {
                return new Entries\EntryQueryBuilder(
                    builder: Models\Entry::query()
                );
            }
        );

        return $this;
    }

    private function registerAssetRepository()
    {
        Statamic::repository(
            abstract: AssetRepositoryContract::class,
            concrete: Assets\AssetRepository::class
        );

        $this->app->bind(
            Assets\AssetQueryBuilder::class,
            function ($app) {
                return new Assets\AssetQueryBuilder(
                    builder: Models\Asset::query()
                );
            }
        );

        return $this;
    }
}
