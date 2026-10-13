<?php

namespace App\Services\Marketplace;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductOffer;
use App\Models\ProductRelease;
use App\Models\Technology;
use App\Services\Reviews\ProductRatingSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceQuery
{
    private const SEARCH_DOCUMENT = "to_tsvector('simple', coalesce(name,'') || ' ' || coalesce(short_description,'') || ' ' || coalesce(business_problem,'') || ' ' || coalesce(target_audience::text,''))";

    /**
     * Central public boundary; file rows are validated by Phase 5 before publication.
     *
     * @return Builder<Product>
     */
    public function visible(): Builder
    {
        return Product::query()->where('products.status', 'published')->where('products.is_active', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->whereHas('offers', fn ($q) => $q->where('is_active', true))
            ->whereHas('cover', fn ($q) => $this->publicMedia($q))
            ->whereHas('screenshots', fn ($q) => $this->publicMedia($q), '>=', 2)
            ->whereHas('releases', fn ($q) => $this->publicRelease($q));
    }

    /** @param Builder<ProductRelease> $query
     * @return Builder<ProductRelease>
     */
    public function publicRelease(Builder $query): Builder
    {
        $disk = config('product_files.artifact_disk');
        $private = config("filesystems.disks.$disk.visibility") === 'private'
            && ! config("filesystems.disks.$disk.serve", false) && ! config("filesystems.disks.$disk.url");

        return $query->whereRaw($private ? '1 = 1' : '1 = 0')->where('status', 'published')->whereNotNull('released_at')
            ->whereHas('artifacts', fn ($a) => $a->where('type', 'source_package')->where('is_current', true)
                ->where('disk', $disk)->where('size_bytes', '>', 0));
    }

    /** @param Builder<ProductMedia> $query
     * @return Builder<ProductMedia>
     */
    public function publicMedia(Builder $query): Builder
    {
        // Refuse private/misconfigured disks, including stale media on another disk.
        $disk = config('product_files.media_disk');
        $safe = $disk !== config('product_files.artifact_disk') && config("filesystems.disks.$disk.visibility") === 'public';

        return $query->whereRaw($safe ? '1 = 1' : '1 = 0')->where('disk', $disk)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->where('size_bytes', '>', 0)->where('width', '>=', 320)->where('height', '>=', 180);
    }

    /** @return Builder<Product> */
    public function cards(bool $detail = false): Builder
    {
        return $this->visible()->select($detail ? ['products.*'] : ['products.id', 'category_id', 'name', 'slug', 'short_description', 'origin', 'currency', 'is_featured'])
            ->addSelect([
                'rating_count' => app(ProductRatingSummary::class)->publicQuery()->selectRaw('COUNT(*)')->whereColumn('product_id', 'products.id'),
                'rating_sum' => app(ProductRatingSummary::class)->publicQuery()->selectRaw('COALESCE(SUM(rating),0)')->whereColumn('product_id', 'products.id'),
                'starting_price' => ProductOffer::selectRaw('MIN(price)')->whereColumn('product_id', 'products.id')->where('is_active', true),
                'published_at' => $this->publicRelease(ProductRelease::selectRaw('MAX(released_at)')->whereColumn('product_id', 'products.id')),
            ])->with(['category:id,name,slug', 'industries' => fn ($q) => $q->where('is_active', true)->orderBy('name')->select('industries.id', 'name', 'slug'),
                'cover' => fn ($q) => $this->publicMedia($q->getQuery())]);
    }

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        // Presentation mode is bounded and never changes the commercial query.
        $view = $request->query('view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'industry' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'technology' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'industries' => ['nullable', 'array', 'max:20'],
            'industries.*' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'technologies' => ['nullable', 'array', 'max:20'],
            'technologies.*' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'currency' => ['nullable', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'min_price' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'max_price' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'sort' => ['nullable', Rule::in(['recommended', 'newest', 'price_asc', 'price_desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        if (isset($filters['min_price'], $filters['max_price'])) {
            $minor = function (string $value): int {
                [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

                return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            };
            if ($minor($filters['max_price']) < $minor($filters['min_price'])) {
                throw ValidationException::withMessages(['max_price' => 'Maximum price must be at least the minimum price.']);
            }
        }
        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $filters['currency'] ??= 'INR';
        }

        foreach (['industries', 'technologies'] as $group) {
            if (isset($filters[$group])) {
                $filters[$group] = array_values(array_unique($filters[$group]));
                sort($filters[$group]);
            }
        }

        $filters['view'] = is_string($view) && in_array($view, ['grid', 'list'], true) ? $view : 'grid';

        return $filters;
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Product>
     */
    public function browse(array $filters): Builder
    {
        $query = $this->cards();
        foreach (['category', 'industry', 'technology'] as $key) {
            if (! empty($filters[$key])) {
                $relation = ['category' => 'category', 'industry' => 'industries', 'technology' => 'technologies'][$key];
                $query->whereHas($relation, fn ($q) => $q->where('slug', $filters[$key])->where('is_active', true));
            }
        }
        // OR within a multi-select group; AND between groups. EXISTS avoids pivot duplicates.
        foreach (['industries', 'technologies'] as $relation) {
            if (! empty($filters[$relation])) {
                $query->whereHas($relation, fn ($q) => $q->whereIn('slug', $filters[$relation])->where('is_active', true));
            }
        }
        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->whereRaw('(SELECT MIN(price) FROM product_offers WHERE product_id = products.id AND is_active = ?) '.$operator.' CAST(? AS DECIMAL(12,2))', [true, $filters[$key]]);
            }
        }
        if (! empty(trim($filters['search'] ?? ''))) {
            $this->search($query, trim($filters['search']));
        }

        return match ($filters['sort'] ?? 'recommended') {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('products.id'),
            'price_asc', 'price_desc' => $query->orderBy('currency')->orderBy('starting_price', $filters['sort'] === 'price_asc' ? 'asc' : 'desc')->orderBy('products.id'),
            default => $query->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('products.id'),
        };
    }

    /** @param Builder<Product> $query */
    private function search(Builder $query, string $text): void
    {
        $escaped = addcslashes(mb_strtolower($text), '%_\\');
        $prefix = $escaped.'%';
        $term = '%'.$escaped.'%';
        $query->where(function ($q) use ($text, $term, $prefix): void {
            if (DB::getDriverName() === 'pgsql') {
                $q->whereRaw(self::SEARCH_DOCUMENT." @@ plainto_tsquery('simple', ?)", [$text])
                    ->orWhereRaw("LOWER(products.name) LIKE ? ESCAPE '\\'", [$prefix]);
            } else {
                $q->where(function ($q) use ($term): void {
                    foreach (['name', 'short_description', 'business_problem', 'target_audience'] as $column) {
                        $q->orWhereRaw("LOWER(products.$column) LIKE ? ESCAPE '\\'", [$term]);
                    }
                });
            }
            foreach (['category' => 'name', 'industries' => 'name', 'features' => 'title'] as $relation => $column) {
                $q->orWhereHas($relation, function ($related) use ($relation, $column, $term): void {
                    $related->whereRaw("LOWER($column) LIKE ? ESCAPE '\\'", [$term]);
                    if ($relation !== 'features') {
                        $related->where('is_active', true);
                    }
                });
            }
        })->orderByRaw("CASE WHEN LOWER(products.name) LIKE ? ESCAPE '\\' THEN 0 ELSE 1 END", [$term]);
    }

    /** @return array<string, array<array{name: string, slug: string}>> */
    public function facets(): array
    {
        $map = fn ($model) => $model::active()->ordered()->limit(100)->get(['name', 'slug'])->map->only(['name', 'slug'])->all();

        return ['categories' => $map(Category::class), 'industries' => $map(Industry::class), 'technologies' => $map(Technology::class)];
    }
}
