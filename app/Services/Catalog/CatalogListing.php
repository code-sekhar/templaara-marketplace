<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogListing
{
    /** @return array<string, mixed> */
    public function admin(Request $request, Model $model, string $kind): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(['order', 'name', 'newest'])],
            'parent' => ['nullable', 'regex:/^(root|[1-9][0-9]*)$/'],
        ]);
        $query = $model->newQuery();
        if ($model instanceof Category) {
            $query->with('parent:id,name');
            if (! empty($filters['parent'])) {
                $filters['parent'] === 'root'
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $filters['parent']);
            }
        }
        if (! empty($filters['search'])) {
            // LOWER works consistently on PostgreSQL and SQLite; escape LIKE wildcards.
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$term]));
        }
        if (! empty($filters['active'])) {
            $query->where('is_active', $filters['active'] === 'active');
        }
        match ($filters['sort'] ?? 'order') {
            'name' => $query->orderBy('name')->orderBy('id'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };

        return [
            'kind' => $kind,
            'records' => $query->paginate(15)->withQueryString(),
            'filters' => $filters,
            'parents' => $model instanceof Category ? Category::orderBy('name')->get(['id', 'name']) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function form(Model $model, string $kind): array
    {
        return [
            'kind' => $kind,
            'record' => $model->exists ? $model : null,
            'parents' => $model instanceof Category
                ? Category::where(fn ($query) => $query->where('is_active', true)->orWhere('id', $model->parent_id))
                    ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
                    ->orderBy('name')->get(['id', 'name'])
                : [],
        ];
    }
}
