<?php

namespace Entries;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades;
use Statamic\Facades\Path;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\EntriesStore;
use Tests\TestCase;
use Thoughtco\StatamicStacheSqlite\Drivers\StacheDriver;
use Thoughtco\StatamicStacheSqlite\Models\Entry as EntryModel;

class EntriesStoreTest extends TestCase
{
    use RefreshDatabase;

    private $parent;

    private $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // $this->parent = (new EntriesStore)->directory(
        $this->directory = Path::resolve(__DIR__.'/../__fixtures__/content/collections');
        // );

        // Stache::registerStore($this->parent);

        Stache::store('entries')->directory($this->directory);
    }

    #[Test]
    public function it_gets_nested_files()
    {
        $dir = $this->directory;

        $files = (new StacheDriver)->all(EntryModel::make(), 'handle', function () use ($dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            $files = [];
            foreach ($iterator as $file) {
                $files[] = $file->getPathname();
            }

            return $files;
        });

        $this->assertEquals(collect([
            $dir.'/alphabetical/alpha.md',
            $dir.'/alphabetical/bravo.md',
            $dir.'/alphabetical/zulu.md',
        ])->sort()->values()->all(), $files->where('collection', 'alphabetical')->pluck('path')->sort()->values()->all());

        $this->assertEquals(collect([
            $dir.'/blog/2017-25-12.christmas.md',
            $dir.'/blog/2018-07-04.fourth-of-july.md',
        ])->sort()->values()->all(), $files->where('collection', 'blog')->pluck('path')->sort()->values()->all());

        $this->assertEquals(collect([
            $dir.'/numeric/one.md',
            $dir.'/numeric/two.md',
            $dir.'/numeric/three.md',
        ])->sort()->values()->all(), $files->where('collection', 'numeric')->pluck('path')->sort()->values()->all());

        $this->assertEquals(collect([
            $dir.'/pages/about.md',
            $dir.'/pages/about/board.md',
            $dir.'/pages/about/directors.md',
            $dir.'/pages/blog.md',
            $dir.'/pages/contact.md',
            $dir.'/pages/home.md',
        ])->sort()->values()->all(), $files->where('collection', 'pages')->pluck('path')->sort()->values()->all());
    }

    #[Test]
    public function it_makes_entry_instances_from_files()
    {
        EntryModel::query()->delete(); // remove fixture data

        Facades\Collection::shouldReceive('findByHandle')->with('blog')->andReturn(
            (new \Statamic\Entries\Collection)->handle('blog')->dated(true)
        );

        // we need to refactor flatfile to allow us to makeItemFromFile for these tests to be meaningful
        $item = (new EntryModel)->makeItemFromFile(
            Path::tidy($this->directory).'/blog/2017-01-02.my-post.md',
            "id: 123\ntitle: Example\nfoo: bar"
        );

        $this->assertInstanceOf(Entry::class, $item);
        $this->assertEquals('123', $item->id());
        $this->assertEquals('Example', $item->get('title'));
        $this->assertEquals(['title' => 'Example', 'foo' => 'bar'], $item->data()->all());
        $this->assertTrue(Carbon::createFromFormat('Y-m-d H:i', '2017-01-02 00:00')->eq($item->date()));
        $this->assertEquals('my-post', $item->slug());
        $this->assertTrue($item->published());
    }

    #[Test]
    public function if_slugs_are_not_required_the_filename_still_becomes_the_slug()
    {
        EntryModel::query()->delete(); // remove fixture data

        Facades\Collection::shouldReceive('findByHandle')->with('blog')->andReturn(
            (new \Statamic\Entries\Collection)->handle('blog')->requiresSlugs(false)
        );

        $item = (new EntryModel)->makeItemFromFile(
            Path::tidy($this->directory).'/blog/the-slug.md',
            "id: 123\ntitle: Example\nfoo: bar"
        );

        $this->assertEquals('123', $item->id());
        $this->assertEquals('the-slug', $item->slug());
    }

    #[Test]
    public function if_slugs_are_not_required_and_the_filename_is_the_same_as_the_id_then_slug_is_null()
    {
        EntryModel::query()->delete(); // remove fixture data
        Facades\Blink::forget('collection-blog'); // remove the collection cache

        Facades\Collection::shouldReceive('findByHandle')->with('blog')->andReturn(
            (new \Statamic\Entries\Collection)->handle('blog')->requiresSlugs(false)
        );

        $item = (new EntryModel)->makeItemFromFile(
            Path::tidy($this->directory).'/blog/123.md',
            "id: 123\ntitle: Example\nfoo: bar"
        );

        $this->assertEquals('123', $item->id());
        $this->assertNull($item->slug());
    }

    #[Test]
    public function if_slugs_are_required_and_the_filename_is_the_same_as_the_id_then_slug_is_the_id()
    {
        EntryModel::query()->delete(); // remove fixture data

        Facades\Collection::shouldReceive('findByHandle')->with('blog')->andReturn(
            (new \Statamic\Entries\Collection)->handle('blog')->requiresSlugs(true)
        );

        $item = (new EntryModel)->makeItemFromFile(
            Path::tidy($this->directory).'/blog/123.md',
            "id: 123\ntitle: Example\nfoo: bar"
        );

        $this->assertEquals('123', $item->id());
        $this->assertEquals('123', $item->slug());
    }

    #[Test]
    public function it_saves_to_disk()
    {
        $entry = Facades\Entry::make()
            ->id('123')
            ->slug('test')
            ->collection('blog')
            ->date('2017-07-04');

        $model = EntryModel::make()
            ->fromContract($entry);

        $model->save();

        $this->assertStringEqualsFile($path = $this->directory.'/blog/2017-07-04.test.md', $entry->fileContents());
        @unlink($path);
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function it_saves_to_disk_with_modified_path()
    {
        $entry = Facades\Entry::make()
            ->id('123')
            ->slug('test')
            ->collection('blog')
            ->date('2017-07-04');

        EntryModel::make()->fromContract($entry)->save();

        $this->assertStringEqualsFile($initialPath = $this->directory.'/blog/2017-07-04.test.md', $entry->fileContents());

        $entry->slug('updated');
        $entry->save();

        $this->assertStringEqualsFile($path = $this->directory.'/blog/2017-07-04.updated.md', $entry->fileContents());

        @unlink($initialPath);
        @unlink($path);
    }

    #[Test]
    public function it_appends_suffix_to_the_filename_if_one_already_exists()
    {
        $existingPath = $this->directory.'/blog/2017-07-04.test.md';
        file_put_contents($existingPath, $existingContents = "---\nid: existing-id\n---");

        $entry = Facades\Entry::make()->id('new-id')->slug('test')->collection('blog')->date('2017-07-04');

        EntryModel::make()->fromContract($entry)->save();

        $newPath = $this->directory.'/blog/2017-07-04.test.1.md';
        $this->assertStringEqualsFile($existingPath, $existingContents);
        $this->assertStringEqualsFile($newPath, $entry->fileContents());

        $anotherEntry = Facades\Entry::make()->id('another-new-id')->slug('test')->collection('blog')->date('2017-07-04');

        EntryModel::make()->fromContract($anotherEntry)->save();

        $anotherNewPath = $this->directory.'/blog/2017-07-04.test.2.md';
        $this->assertStringEqualsFile($existingPath, $existingContents);
        $this->assertStringEqualsFile($anotherNewPath, $anotherEntry->fileContents());

        @unlink($newPath);
        @unlink($anotherNewPath);
        @unlink($existingPath);
        $this->assertFileDoesNotExist($newPath);
        $this->assertFileDoesNotExist($anotherNewPath);
        $this->assertFileDoesNotExist($existingPath);
    }

    #[Test]
    public function it_doesnt_append_the_suffix_to_the_filename_if_it_is_itself()
    {
        $existingPath = $this->directory.'/blog/2017-07-04.test.md';
        file_put_contents($existingPath, "---\nid: the-id\n---");

        $entry = Facades\Entry::make()
            ->id('the-id')
            ->slug('test')
            ->collection('blog')
            ->date('2017-07-04');

        EntryModel::make()->fromContract($entry)->save();

        $pathWithSuffix = $this->directory.'/blog/2017-07-04.test.1.md';
        $this->assertStringEqualsFile($existingPath, $entry->fileContents());

        @unlink($existingPath);
        $this->assertFileDoesNotExist($pathWithSuffix);
        $this->assertFileDoesNotExist($existingPath);
    }

    #[Test]
    public function it_doesnt_append_the_suffix_to_an_already_suffixed_filename_if_it_is_itself()
    {
        $suffixlessExistingPath = $this->directory.'/blog/2017-07-04.test.md';
        file_put_contents($suffixlessExistingPath, "---\nid: the-id\n---");
        $suffixedExistingPath = $this->directory.'/blog/2017-07-04.test.md';
        file_put_contents($suffixedExistingPath, "---\nid: another-id\n---");

        $entry = Facades\Entry::make()
            ->id('another-id')
            ->slug('test')
            ->collection('blog')
            ->date('2017-07-04');

        EntryModel::make()->fromContract($entry)->save();

        $pathWithIncrementedSuffix = $this->directory.'/blog/2017-07-04.test.2.md';
        $this->assertStringEqualsFile($suffixedExistingPath, $entry->fileContents());
        @unlink($suffixedExistingPath);
        $this->assertFileDoesNotExist($pathWithIncrementedSuffix);
        $this->assertFileDoesNotExist($suffixedExistingPath);
    }

    #[Test]
    public function it_keeps_the_suffix_even_if_the_suffixless_path_is_available()
    {
        $existingPath = $this->directory.'/blog/2017-07-04.test.1.md';
        $suffixlessPath = $this->directory.'/blog/2017-07-04.test.md';

        file_put_contents($existingPath, 'id: 456');

        $entry = (new EntryModel)->makeItemFromFile(
            $existingPath,
            file_get_contents($existingPath)
        );

        EntryModel::make()->fromContract($entry)->save();

        $this->assertStringEqualsFile($existingPath, $entry->fileContents());
        $this->assertFileDoesNotExist($suffixlessPath);

        @unlink($existingPath);
        $this->assertFileDoesNotExist($existingPath);
    }

    #[Test]
    public function it_removes_the_suffix_if_it_previously_had_one_but_needs_a_new_path_anyway()
    {
        // eg. if the slug is changing, and the filename would be changing anyway,
        // we shouldn't maintain the suffix.

        $existingPath = $this->directory.'/blog/2017-07-04.test.1.md';
        $newPath = $this->directory.'/blog/2017-07-04.updated.md';

        file_put_contents($existingPath, 'id: 456');

        $entry = (new EntryModel)->makeItemFromFile(
            $existingPath,
            file_get_contents($existingPath)
        );

        $entry->slug('updated');

        EntryModel::make()->fromContract($entry)->save();

        $this->assertStringEqualsFile($newPath, $entry->fileContents());
        $this->assertFileDoesNotExist($existingPath);

        @unlink($newPath);
        $this->assertFileDoesNotExist($newPath);
    }

    #[Test]
    public function it_ignores_entries_in_a_site_subdirectory_where_the_collection_doesnt_have_that_site_enabled()
    {
        $this->markTestIncomplete();
    }
}
