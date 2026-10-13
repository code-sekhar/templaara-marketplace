<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Technology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $records = Category::active()->ordered()->with(['parent' => fn ($query) => $query->where('is_active', true)->select('id')])
            ->paginate($this->perPage($request), ['id', 'name', 'slug', 'description', 'icon', 'parent_id']);
        $records->through(function (Category $category): array {
            return [
                ...$category->only(['id', 'name', 'slug', 'description', 'icon']),
                'parent_id' => $category->parent?->id,
            ];
        });

        return response()->json($records);
    }

    public function industries(Request $request): JsonResponse
    {
        return response()->json(Industry::active()->ordered()->paginate($this->perPage($request), ['id', 'name', 'slug', 'description', 'icon']));
    }

    public function technologies(Request $request): JsonResponse
    {
        return response()->json(Technology::active()->ordered()->paginate($this->perPage($request), ['id', 'name', 'slug', 'description', 'logo', 'website_url']));
    }

    private function perPage(Request $request): int
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($validated['per_page'] ?? 50);
    }
}
