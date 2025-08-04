<?php

namespace Thoughtco\StatamicStacheSqlite\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

use function Laravel\Prompts\spin;

class ClearCache extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:flatfile:clear';
    protected $description = 'Build the flatfile SQL cache';

    public function handle()
    {
        spin(
            fn () => Flatfile::clear(),
            message: 'Clearing the flatfile cache...',
        );

        $this->components->info('It\'s gone...');
    }
}
