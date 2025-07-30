<?php

namespace Thoughtco\StatamicStacheSqlite\Drivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Statamic\Entries\GetSuffixFromPath;
use Statamic\Entries\RemoveSuffixFromPath;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Contracts\Driver;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

class StacheDriver implements Driver
{
    public function shouldRestoreCache(Model $model, array $resolvers): bool
    {
        // if there is no watcher, dont rebuild the cache
        if (! Stache::isWatcherEnabled()) {
            return false;
        }

        $databaseLastUpdated = filemtime(Flatfile::getDatabasePath());

        foreach ($resolvers as $fileResolver) {
            foreach ($fileResolver() as $file) {
                if ($file->getMTime() > $databaseLastUpdated) {
                    return true;
                }
            }
        }

        return false;
    }

    public function save(Model $model, string $directory): bool
    {
        $path = $this->filepath($directory, $model);

        if ($model->file_path_read_from && ($path != $model->file_path_read_from)) {
            unlink($model->file_path_read_from);
        }

        $fs = new Filesystem;
        $fs->ensureDirectoryExists(dirname($path));

        file_put_contents($path, $model->fileContents());

        return true;
    }

    public function delete(Model $model, string $directory): bool
    {
        unlink($this->filepath($directory, $model));

        return true;
    }

    public function all(Model $model, \Closure $fileResolver): Collection
    {
        ray()->measure('reading_flatfiles: '.get_class($model));

        // @TODO: change this to be a lazy collection from a memory and speed perspective
        // if so, chunk in StoreAsFlatFile will also need changed
        $collection = Collection::make();

        /** @var \SplFileInfo|string $file */
        foreach ($fileResolver() as $file) { // @TODO: this should really just expect an array of filtered string file paths
            $path = $file;
            if (! is_string($file)) {
                if ($file->isDir()) {
                    continue;
                }

                if ($file->getExtension() !== $model->fileExtension()) {
                    continue;
                }

                $path = $file->getPathname();
            }

            // let the model determine how to parse the data
            $data = $model->newInstance()->fromPath($path);

            if (! $data) {
                continue;
            }

            $row = array_merge(
                $data,
                [
                    'path' => $path,
                    'file_path_read_from' => $path,
                ]
            );

            $collection->push($row);
        }

        ray()->measure('reading_flatfiles: '.get_class($model));

        return $collection;
    }

    public function filepath(string $directory, Model $model): string
    {
        $basePath = $directory.DIRECTORY_SEPARATOR.$model->{$model->getPathKeyName()}.'.'.$model->fileExtension();
        $itemPath = $model->file_path_read_from ?? $basePath;

        $suffixlessPath = (new RemoveSuffixFromPath)($itemPath);

        if ($basePath !== $suffixlessPath) {
            // If the path should change (e.g. a new slug or date) then
            // reset the counter to 1 so the suffix doesn't get maintained.
            $num = 0;
        } else {
            // Otherwise, start from whatever the suffix was.
            $num = (new GetSuffixFromPath)($itemPath) ?? 0;
        }

        while (true) {
            $ext = '.'.$model->fileExtension();
            $filename = Str::beforeLast($basePath, $ext);
            $suffix = $num ? ".$num" : '';
            $path = "{$filename}{$suffix}{$ext}";

            if (! $contents = File::get($path)) {
                break;
            }

            $itemFromDisk = $model->makeItemFromFile($path, $contents);

            if ($model->id == $itemFromDisk->id()) {
                break;
            }

            $num++;
        }

        return $path;
    }
}
