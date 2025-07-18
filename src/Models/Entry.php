<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Stache;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\Flatfile;

class Entry extends Model
{
    use Flatfile;

    public static $driver = 'stache';
    public string $path = '';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
        ];
    }

    public function getKeyName()
    {
        return 'path';
    }

    public static function getOrbitalPath()
    {
        return rtrim(Stache::store('entries')->directory(), '/');
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = app(EntryContract::class)::make();

        foreach ($this->getAttributes() as $key => $value) {
            if ($key == 'created_at' || $key == 'updated_at') {
                continue;
            }

            if ($value !== null) {
                if ($key == 'data' && is_string($value)) {
                    $value = json_decode($value, true);
                }

                if ($key == 'date' && ! $value) {
                    continue;
                }

                $contract->$key($value);
            }
        }

        return $contract;
    }

    public function fromPath(string $path)
    {
        $path = Str::after($path, static::getOrbitalPath().DIRECTORY_SEPARATOR);

        $collectionHandle = Str::beforeLast($path, DIRECTORY_SEPARATOR);

        $data = [
            'collection' => $collectionHandle,
            'site' => 'default',
        ];

        // need to date, site etc
        $slug = Str::of($path)->after($collectionHandle.DIRECTORY_SEPARATOR)->before('.md');

        if ($slug->contains(DIRECTORY_SEPARATOR)) {
            $data['site'] = (string) $slug->before(DIRECTORY_SEPARATOR);
            $slug = $slug->after(DIRECTORY_SEPARATOR);
        }

        if ($slug->contains('.')) {
            $data['date'] = (string) $slug->before('.');
            $slug = $slug->after('.');
        }

        $data['slug'] = (string) $slug;

        return $data;
    }

    public function fromContract(EntryContract $entry)
    {
        foreach (['id', 'data', 'date', 'published', 'slug'] as $key) {
            $this->$key = $entry->{$key}();
        }

        $collection = $entry->collection();

        $this->blueprint = $entry->blueprint()->handle();
        $this->collection = $collection->handle();
        $this->site = $entry->locale();

        if (! $this->id) {
            $this->id = Str::uuid()->toString();
        }

        $this->path = Str::of($entry->buildPath())->after(static::getOrbitalPath().DIRECTORY_SEPARATOR)->beforeLast('.md');

        return $this;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique();
        //$table->string('path');
        $table->string('blueprint');
        $table->string('collection');
        $table->json('data');
        $table->date('date')->nullable();
        $table->boolean('published');
        $table->string('site');
        $table->string('slug');
    }
}
