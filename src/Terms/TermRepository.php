<?php

namespace Thoughtco\StatamicStacheSqlite\Terms;

use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Stache\Repositories\TermRepository as StacheRepository;
use Statamic\Support\Str;

class TermRepository extends StacheRepository
{
    public function find($id): ?TermContract
    {
        [$handle, $slug] = explode('::', $id);

        $blinkKey = "term-{$id}";
        $term = Blink::once($blinkKey, function () use ($handle, $slug) {
            return $this->query()
                ->where('taxonomy', $handle)
                ->where('slug', $slug)
                ->get()
                ->first();
        });

        if (! $term) {
            Blink::forget($blinkKey);

            return null;
        }

        return $term;
    }

    public function findByUri(string $uri, ?string $site = null): ?TermContract
    {
        $site = $site ?? Site::default()->handle();

        if ($substitute = $this->substitutionsByUri[$site.'@'.$uri] ?? null) {
            return $substitute;
        }

        $collection = Collection::all()
            ->first(function ($collection) use ($uri, $site) {
                if (Str::startsWith($uri, $collection->uri($site))) {
                    return true;
                }

                return Str::startsWith($uri, '/'.$collection->handle());
            });

        if ($collection) {
            $uri = Str::after($uri, $collection->uri($site) ?? $collection->handle());
        }

        $uri = Str::removeLeft($uri, '/');

        [$taxonomy, $slug] = array_pad(explode('/', $uri), 2, null);

        if (! $slug) {
            return null;
        }

        if (! $taxonomy = $this->findTaxonomyHandleByUri(Str::ensureLeft($taxonomy, '/'))) {
            return null;
        }

        $blinkKey = 'term-'.md5(urlencode($uri)).($site ? '-'.$site : '');
        $term = Blink::once($blinkKey, function () use ($slug, $taxonomy) {
            return $this->query()
                ->where('slug', $slug)
                ->where('taxonomy', $taxonomy)
                ->first();
        });

        if (! $term) {
            Blink::forget($blinkKey);

            return null;
        }

        return $term->in($site)?->collection($collection);
    }

    private function findTaxonomyHandleByUri($uri)
    {
        return Taxonomy::all()->first(function ($taxonomy) use ($uri) {
            return $taxonomy->uri() == $uri;
        })?->handle();
    }

    public function query()
    {
        return app(TermQueryBuilder::class); // @TODO: this should be a contract, so we can using bindings()
    }

    public function save($term)
    {
        $model = $term->toModel();
        $model->save();

        $term->model($model);

        Blink::put("term-{$term->id()}", $term);
    }

    public function delete($term)
    {
        $term->model()->delete();

        Blink::forget("term-{$term->id()}");
    }

    public static function bindings(): array
    {
        return [
            TermContract::class => Term::class,
        ];
    }

    protected function ensureAssociations()
    {
        // don't need this to do anything now
    }
}
