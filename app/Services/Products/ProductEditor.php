<?php

namespace App\Services\Products;

use App\Enums\LicenseType;
use App\Enums\ProductCurrency;
use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Technology;
use App\Services\Products\Files\FilePresentation;
use App\Services\Products\Workflow\ProductReviewWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProductEditor
{
    /** @return array<string, mixed> */
    public function listing(Request $request, bool $admin): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'origin' => $admin ? ['nullable', Rule::enum(ProductOrigin::class)] : ['prohibited'],
        ]);
        $query = Product::query()->select(['id', 'owner_id', 'category_id', 'origin', 'name', 'slug', 'short_description', 'status', 'currency', 'is_active', 'is_featured', 'updated_at'])
            ->with(['category:id,name', 'owner:id,name']);
        if (! $admin) {
            $query->where('owner_id', $request->user()->id)->where('origin', ProductOrigin::Author);
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$term]));
        }
        foreach (['category' => 'category_id', 'status' => 'status', 'origin' => 'origin'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        $records = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(15)->withQueryString();
        $records->through(fn (Product $product) => [
            ...$product->toArray(),
            'can' => [
                'view' => $request->user()->can('view', $product),
                'update' => $request->user()->can('update', $product),
                'delete' => $request->user()->can('delete', $product),
                'submit' => $request->user()->can('submit', $product) && in_array($product->status, [ProductStatus::Draft, ProductStatus::ChangesRequested], true),
            ],
        ]);

        return [
            'context' => $admin ? 'admin' : 'author',
            'records' => $records,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'is_active']),
            'canCreate' => $request->user()->can($admin ? 'createOfficial' : 'create', Product::class),
        ];
    }

    /** @return array<string, mixed> */
    public function form(?Product $product, bool $admin, bool $readOnly = false): array
    {
        $product?->load(['features', 'offers', 'industries:id,name,is_active', 'technologies:id,name,is_active', 'category:id,name', 'owner:id,name']);

        return [
            'updates' => $product ? ProductRevision::where('product_id', $product->id)->with('release:id,version')->orderByDesc('id')->limit(20)->get()->map(fn ($r) => ['id' => $r->id, 'status' => $r->status->value, 'version' => $r->release->version])->all() : [],
            'canCreateUpdate' => $product ? request()->user()->can('create', [ProductRevision::class, $product]) : false,
            'context' => $admin ? 'admin' : 'author',
            'product' => $product ? [...$product->toArray(), ...app(FilePresentation::class)->current($product)] : null,
            'readOnly' => $readOnly,
            'categories' => Category::where(fn ($query) => $query->where('is_active', true)->orWhere('id', $product?->category_id))->ordered()->get(['id', 'name', 'is_active']),
            'industries' => Industry::where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $product?->industries->modelKeys() ?? []))->ordered()->get(['id', 'name', 'is_active']),
            'technologies' => Technology::where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $product?->technologies->modelKeys() ?? []))->ordered()->get(['id', 'name', 'is_active']),
            'currencies' => array_column(ProductCurrency::cases(), 'value'),
            'licenseTypes' => array_column(LicenseType::cases(), 'value'),
            'canFeature' => $admin,
            'canEditFiles' => $product ? request()->user()->can('update', $product) : false,
            'fileLimits' => Arr::except(config('product_files'), ['media_disk', 'artifact_disk']),
            'history' => $product ? app(ProductReviewWorkspace::class)->history($product) : null,
            'workflowActions' => $product ? app(ProductReviewWorkspace::class)->actions(request(), $product) : [],
        ];
    }
}
