<?php

namespace Thoughtco\StatamicStacheSqlite\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use ReflectionClass;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Thoughtco\StatamicStacheSqlite\Contracts\Driver;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileCreated;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileDeleted;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileUpdated;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

trait StoreAsFlatfile
{
    protected $schemaColumns;

    protected static $blueprintColumns;

    public function getPathKeyName(): string
    {
        return 'path';
    }

    public static function bootStoreAsFlatfile()
    {
        if (! static::enableFlatfile()) {
            return;
        }

        $driver = Flatfile::driver(static::getFlatfileDriver());
        $modelFile = (new ReflectionClass(static::class))->getFileName();

        $model = (new static);

        $dbPath = Flatfile::getDatabasePath();
        if (! file_exists($dbPath)) {
            File::ensureDirectoryExists(dirname($dbPath));
            touch($dbPath);
        }

        if (
            Flatfile::isTesting() ||
            filemtime($modelFile) > filemtime($dbPath) ||
            $driver->shouldRestoreCache($model, static::getFlatfileResolvers()) ||
            ! static::resolveConnection()->getSchemaBuilder()->hasTable($model->getTable())
        ) {
            $model->migrate();
        }

        static::created(function (Model $model) {
            if ($model->callTraitMethod('shouldCreate', $model) === false) {
                return;
            }

            // We need to refresh the model so that we can get all of the columns
            // and default values from the SQLite cache.
            // $model->refresh();

            $status = Flatfile::driver(static::getFlatfileDriver())->save($model);

            event(new FlatfileCreated($model));

            return $status;
        });

        static::updated(function (Model $model) {
            if ($model->callTraitMethod('shouldUpdate', $model) === false) {
                return;
            }

            $status = Flatfile::driver(static::getFlatfileDriver())->save($model);

            event(new FlatfileUpdated($model));

            return $status;
        });

        static::deleted(function (Model $model) {
            if ($model->callTraitMethod('shouldDelete', $model) === false) {
                return;
            }

            $status = Flatfile::driver(static::getFlatfileDriver())->delete($model);

            event(new FlatfileDeleted($model));

            return $status;
        });
    }

    public static function schema(Blueprint $table)
    {
        //
    }

    public static function resolveConnection($connection = null)
    {
        if (! static::enableFlatfile()) {
            return parent::resolveConnection($connection);
        }

        return static::$resolver->connection('statamic');
    }

    public function getConnectionName()
    {
        if (! static::enableFlatfile()) {
            return parent::getConnectionName();
        }

        return 'statamic';
    }

    public function migrate()
    {
        $table = $this->getTable();

        /** @var \Illuminate\Database\Schema\Builder $schema */
        $schema = static::resolveConnection()->getSchemaBuilder();

        if ($schema->hasTable($table)) {
            $schema->drop($table);
        }

        /** @var \Illuminate\Database\Schema\Blueprint|null $blueprint */
        static::$blueprintColumns = null;

        $schema->create($table, function (Blueprint $table) use (&$blueprint) {
            static::schema($table);

            $this->callTraitMethod('schema', $table);

            $driver = Flatfile::driver(static::getFlatfileDriver());

            if (method_exists($driver, 'schema')) {
                $driver->schema($table);
            }

            if ($this->usesTimestamps()) {
                $table->timestamps();
            }

            static::$blueprintColumns = $table->getColumns();
        });

        $driver = Flatfile::driver(static::getFlatfileDriver());

        $afterInsert = collect();
        $insertedIds = [];
        foreach (static::getFlatfileResolvers() as $handle => $directory) {
            $driver->all($this, $handle, $directory)
                ->chunk(500)
                ->each(function (LazyCollection $chunk) use ($afterInsert, &$insertedIds) {
                    $insertWithoutUpdate = $chunk->map(function ($row) use ($afterInsert, &$insertedIds) {
                        $row = $this->prepareDataForModel($row);

                        if (isset($row['updateAfterInsert'])) {
                            $afterInsert->push(['id' => $row['id'], 'updateAfterInsert' => $row['updateAfterInsert']]);
                        } else {
                            $insertedIds[] = $row['id'];
                        }

                        unset($row['updateAfterInsert']);

                        return $row;
                    });

                    static::insert($insertWithoutUpdate->toArray());
                });
        }

        // @TODO: if we dont do the below we save nearly 50% of processing on my demo site... I would love to find ways to remove it!

        // ensure we update in the sequence the repository needs
        while ($afterInsert->isNotEmpty()) {
            foreach ($afterInsert as $index => $row) {
                $values = $row['updateAfterInsert']($insertedIds);

                if ($values === false) { // the repository doesnt have what it needs yet
                    continue;
                }

                static::newQuery()->where('id', $row['id'])->update($values);
                $insertedIds[] = $row['id'];
                $afterInsert->forget($index);
            }
        }
    }

    protected function getSchemaColumns(): array
    {
        if ($this->schemaColumns) {
            return $this->schemaColumns;
        }

        $this->schemaColumns = static::resolveConnection()->getSchemaBuilder()->getColumnListing($this->getTable());

        return $this->schemaColumns;
    }

    protected function prepareDataForModel(array $row)
    {
        $columns = $this->getSchemaColumns();

        $newRow = collect($row)
            ->filter(fn ($_, $key) => in_array($key, $columns))
            ->map(function ($value, $key) use ($row) {
                try {
                    $this->setAttribute($key, $value);

                    return $this->attributes[$key];
                } catch (\Exception $e) {
                    dd($value, $key, $row);
                }
            })
            ->all();

        foreach ($columns as $column) {
            if (array_key_exists($column, $newRow)) {
                continue;
            }

            $definition = static::$blueprintColumns->firstWhere('name', $column);

            if ($definition->default !== null) {
                $newRow[$column] = $definition->default;
            } elseif ($definition->nullable) {
                $newRow[$column] = null;
            }
        }

        $newRow['updateAfterInsert'] = $row['updateAfterInsert'] ?? null;

        return $newRow;
    }

    protected static function getFlatfileDriver()
    {
        return property_exists(static::class, 'driver') ? static::$driver : null;
    }

    public static function enableFlatfile(): bool
    {
        return true;
    }

    public static function getFlatfileName(): string
    {
        return (string) Str::of(class_basename(static::class))->snake()->lower()->plural();
    }

    public static function getFlatfileResolvers(): array
    {
        return [];
    }

    public function getFlatFileRootDirectory(): string
    {
        return '';
    }

    public function callTraitMethod(string $method, ...$args)
    {
        $result = null;

        foreach (class_uses_recursive(static::class) as $trait) {
            $methodToCall = $method.class_basename($trait);

            if (method_exists($this, $methodToCall)) {
                $result = $this->{$methodToCall}(...$args);
            }
        }

        return $result;
    }

    public function fileContents()
    {
        // This method should be clever about what contents to output depending on the
        // file type used. Right now it's assuming markdown. Maybe you'll want to
        // save JSON, etc. TODO: Make it smarter when the time is right.

        $data = $this->fileData();

        if ($this->shouldRemoveNullsFromFileData()) {
            $data = Arr::removeNullValues($data);
        }

        if ($this->fileExtension() === 'yaml') {
            return YAML::dump($data);
        }

        if (! Arr::has($data, 'content')) {
            return YAML::dumpFrontMatter($data);
        }

        $content = $data['content'];

        return $content === null
            ? YAML::dump($data)
            : YAML::dumpFrontMatter(Arr::except($data, 'content'), $content);
    }

    protected function shouldRemoveNullsFromFileData()
    {
        return true;
    }

    public function fileExtension()
    {
        return 'yaml';
    }

    public function deleteFlatfile(Driver $driver)
    {
        unlink($driver->filepath($this->getFlatfileRootDirectory(), $this));

        return true;
    }

    public function writeFlatfile(Driver $driver)
    {
        $path = $driver->filepath($this->getFlatfileRootDirectory(), $this);

        if ($this->file_path_read_from && ($path != $this->file_path_read_from)) {
            unlink($this->file_path_read_from);
        }

        $fs = new Filesystem;
        $fs->ensureDirectoryExists(dirname($path));

        file_put_contents($path, $this->fileContents());

        return true;
    }
}
