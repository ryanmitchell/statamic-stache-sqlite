<?php

namespace Thoughtco\StatamicStacheSqlite;

use Orbit\Facades\Orbit;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
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
