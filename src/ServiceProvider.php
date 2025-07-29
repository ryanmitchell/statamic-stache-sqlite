<?php

namespace Thoughtco\StatamicStacheSqlite;

use Orbit\Facades\Orbit;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    public function boot()
    {
        // @TODO: move all orbit stuff to this package so we dont depend on it
        // make all configs statamic, not orbit, remove orbital etc
        // config()->set('orbit.paths.cache', storage_path('statamic/cache'));

        parent::boot();
    }

    public function bootAddon()
    {
        Orbit::extend('stache', function ($app) {
            return new OrbitDrivers\StacheDriver($app);
        });

        $this->registerEntryRepository();
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
}
