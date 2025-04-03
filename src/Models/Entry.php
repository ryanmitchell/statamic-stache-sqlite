<?php

namespace ThoughtCo\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Orbit\Concerns\Orbital;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Support\Str;

class Entry extends Model
{
    use Orbital;


    public static $driver = 'md';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = app(EntryContract::class)::make();

        foreach ($this->getAttributes() as $key => $value) {
            if ($key == 'content' || $key == 'created_at' || $key == 'updated_at') { // remove once we make a stache driver
                continue;
            }

            if ($value !== null) {
                if ($key == 'data') { // remove once we make a stache driver that honours schema
                    $value = json_decode($value, true);
                }

                $contract->$key($value);
            }
        }

        return $contract;
    }

    public function fromContract(EntryContract $entry)
    {
        foreach (['id', 'data', 'date', 'published', 'slug'] as $key) {
            $this->$key = $entry->{$key}();
        }

        $this->blueprint = $entry->blueprint()->handle();
        $this->collection = $entry->collection()->handle();
        $this->site = $entry->locale();
        ray ($this->site);

        if (! $this->id) {
            $this->id = Str::uuid()->toString();
        }

        return $this;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique();
        $table->string('blueprint');
        $table->string('collection');
        $table->json('data');
        $table->datetime('date')->nullable();
        $table->boolean('published');
        $table->string('site');
        $table->string('slug');
    }
}
