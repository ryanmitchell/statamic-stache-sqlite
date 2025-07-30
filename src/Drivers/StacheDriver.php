<?php

namespace Thoughtco\StatamicStacheSqlite\Drivers;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Statamic\Entries\GetSuffixFromPath;
use Statamic\Entries\RemoveSuffixFromPath;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Contracts\Driver;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

class StacheDriver implements Driver
{
    public function shouldRestoreCache(Model $model, string $directory): bool
    {
        // if there is no watcher, dont rebuild the cache
        if (! Stache::isWatcherEnabled()) {
            return false;
        }

        $databaseLastUpdated = filemtime(Flatfile::getDatabasePath());

        foreach (new FilesystemIterator($directory) as $file) {
            if ($file->getMTime() > $databaseLastUpdated) {
                return true;
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

    public function all(Model $model, string $directory): Collection
    {
        ray()->measure('reading_flatfiles: '.get_class($model));

        // @TODO: change this to be a lazy collection from a memory and speed perspective
        // if so, chunk in StoreAsFlatFile will also need changed
        $withoutOrigin = Collection::make();
        $withOrigin = Collection::make();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $files = $this->all($model, $file->getRealPath());

                $collection->merge($files);

                continue;
            }

            if ($file->getExtension() !== $model->fileExtension()) {
                continue;
            }

            // let the model determine how to parse the data
            $data = $model->newInstance()->fromPath($file->getPathname());

            if (! $data) {
                continue;
            }

            $row = array_merge(
                $data,
                [
                    'path' => $file->getRealPath(),
                    'file_path_read_from' => $file->getRealPath(),
                ]
            );

            // @TODO: this assumes origin, the splitting needs moved to the model
            // if indeed its even necessary
            if ($row['origin'] ?? false) {
                $withOrigin->push($row);
            } else {
                $withoutOrigin->push($row);
            }
        }

        ray()->measure('reading_flatfiles: '.get_class($model));

        return $withoutOrigin->concat($withOrigin);
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
