<?php

namespace Thoughtco\StatamicStacheSqlite\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Thoughtco\StatamicStacheSqlite\Models\Asset;
use Thoughtco\StatamicStacheSqlite\Models\Entry;
use Thoughtco\StatamicStacheSqlite\Models\Term;

use function Laravel\Prompts\spin;

class BuildCache extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:flatfile:cache';

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
        spin(function () {
            (new Asset);
            (new Entry);
            (new Term);
        }, message: 'Warming the flatfile stache...');

        $this->components->info('Its warm and ready');
    }
}
