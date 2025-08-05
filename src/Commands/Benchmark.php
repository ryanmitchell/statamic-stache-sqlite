<?php

namespace Thoughtco\StatamicStacheSqlite\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Assets\AssetRepository;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Repositories\EntryRepository;
use Statamic\Statamic;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;

class Benchmark extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:flatfile:benchmark';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Benchmark the flatfile performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        intro('Running benchmarks for flatfile...');

        table(
            headers: ['Assets', 'Entries'],
            rows: [
                [Asset::query()->count(), Entry::query()->count()],
            ]
        );

        $this->testStache();
        $this->testSqlite();
    }

    private function testSqlite()
    {
        $tests = [];
        for ($i = 0; $i < 10; $i++) {
            $tests[] = function () {
                Blink::flush();
                Flatfile::clear();
                Flatfile::warm();
            };
        }

        $result = \Illuminate\Support\Benchmark::measure($tests);

        info('SQLite');

        table(
            headers: collect(range(1, 10))->map(fn ($i) => "Run $i")->all(),
            rows: [$result]
        );
    }

    private function testStache()
    {
        Facade::clearResolvedInstances();

        Statamic::repository(
            abstract: AssetRepository::class,
            concrete: \Statamic\Assets\AssetRepository::class
        );

        Statamic::repository(
            abstract: EntryRepositoryContract::class,
            concrete: EntryRepository::class
        );

        $tests = [];
        for ($i = 0; $i < 10; $i++) {
            $tests[] = function () {
                Blink::flush();
                Stache::clear();
                Stache::warm();
            };
        }

        $result = \Illuminate\Support\Benchmark::measure($tests);

        info('Stache');

        table(
            headers: collect(range(1, 10))->map(fn ($i) => "Run $i")->all(),
            rows: [$result]
        );
    }
}
