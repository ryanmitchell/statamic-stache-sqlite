<?php

namespace Thoughtco\StatamicStacheSqlite;

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
        $this->registerAssetRepository()
            ->registerEntryRepository();
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
