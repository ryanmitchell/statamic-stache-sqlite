<?php

namespace Thoughtco\StatamicStacheSqlite;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Assets\AssetRepository as AssetRepositoryContract;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Contracts\Entries\TermRepository as TermRepositoryContract;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;
use Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon()
    {
        try {
            $fs = new Filesystem;

            $database = Flatfile::getDatabasePath();

            if (! $fs->exists($database) && $database !== ':memory:') {
                $fs->ensureDirectoryExists(dirname($database));
                $fs->put($database, '');
            }

            // thank you: https://github.com/nunomaduro/laravel-optimize-database/tree/main
            $connection = DB::connection('statamic');

            if (data_get($connection->select('PRAGMA journal_mode'), '0.journal_mode') != 'wal') {
                $connection->unprepared(<<<'SQL'
                PRAGMA auto_vacuum = incremental;
                PRAGMA journal_mode = WAL;
                PRAGMA page_size = 32768;
                SQL
                );
            }

            $connection->unprepared(<<<'SQL'
                PRAGMA busy_timeout = 5000;
                PRAGMA cache_size = -20000;
                PRAGMA foreign_keys = ON;
                PRAGMA incremental_vacuum;
                PRAGMA mmap_size = 2147483648;
                PRAGMA temp_store = MEMORY;
                PRAGMA synchronous = NORMAL;
                SQL
            );
        } catch (\Throwable $e) {
        }

        $this->registerAssetRepository()
            ->registerEntryRepository()
            ->registerTermRepository();
    }

    public function register()
    {
        $this->app->scoped(FlatfileManager::class, function ($app) {
            $manager = new FlatfileManager($app);
            $manager->extend('stache', fn () => new Drivers\StacheDriver($app));

            return $manager;
        });

        $this->app['config']->set('database.connections.statamic', [
            'driver' => 'sqlite',
            'database' => Flatfile::getDatabasePath(),
            'foreign_key_constraints' => false,
        ]);
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

    private function registerTermRepository()
    {
        Statamic::repository(
            abstract: TermRepositoryContract::class,
            concrete: Terms\TermRepository::class
        );

        $this->app->bind(
            Terms\TermsQueryBuilder::class,
            function ($app) {
                return new Terms\TermsQueryBuilder(
                    builder: Models\Terms::query()
                );
            }
        );

        return $this;
    }
}
