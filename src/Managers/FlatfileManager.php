<?php

namespace Thoughtco\StatamicStacheSqlite\Managers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Manager;

class FlatfileManager extends Manager
{
    protected $testing = false;

    public function test()
    {
        $this->testing = true;

        return $this;
    }

    public function isTesting()
    {
        return $this->testing === true || App::environment('testing');
    }

    public function getDefaultDriver()
    {
        return 'stache';
    }

    public function getDatabasePath()
    {
        if ($this->isTesting()) {
            return ':memory:';
        }

        return storage_path('statamic/cache/stache.sqlite');
    }
}
