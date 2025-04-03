<?php

namespace Thoughtco\StatamicStacheSqlite;

use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon()
    {
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
