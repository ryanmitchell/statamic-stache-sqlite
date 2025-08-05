<?php

namespace Thoughtco\StatamicStacheSqlite\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

use function Laravel\Prompts\spin;

class BuildCache extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:flatfile:warm';

    protected $description = 'Build the flatfile SQL cache';

    public function handle()
    {
        spin(
            fn () => Flatfile::warm(),
            message: 'Warming the flatfile cache...',
        );

        $this->components->info('It\'s warm and ready');
    }
}
