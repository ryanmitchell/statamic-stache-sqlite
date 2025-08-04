<?php

namespace Tests\Terms;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Statamic\Facades\Path;
use Statamic\Facades\Stache;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;
use Thoughtco\StatamicStacheSqlite\Models\Term;

class TermsStoreTest extends TestCase
{
    use PreventSavingStacheItemsToDisk;

    private $parent;

    private $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = Path::resolve(__DIR__.'/../__fixtures__/content/taxonomies');

        Stache::store('taxonomies')->directory($this->directory);
        Stache::store('terms')->directory($this->directory);
    }

    #[Test]
    public function it_saves_to_disk()
    {
        $term = Facades\Term::make('test')->taxonomy('tags');
        $term->in('en')->set('title', 'Test');

        $model = Term::make()->fromContract($term);
        $model->save();

        $this->assertStringEqualsFile($path = $this->directory.'/tags/test.yaml', $term->fileContents());
        @unlink($path);
        $this->assertFileDoesNotExist($path);
    }
}
