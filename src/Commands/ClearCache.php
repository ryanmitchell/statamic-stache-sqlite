<?php

namespace Thoughtco\StatamicStacheSqlite\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

class ClearCache extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:flatfile:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the flatfile sql cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = Flatfile::getDatabasePath();

        if (! file_exists($path)) {
            return;
        }

        unlink($path);

        $this->components->info('Its gone...');
    }
}
