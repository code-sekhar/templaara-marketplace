<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;
use App\Services\Catalog\CatalogListing;
use App\Services\Catalog\SaveCatalogRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Index', $listing->admin($request, new Category, 'categories'));
    }

    public function create(CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form(new Category, 'categories'));
    }

    public function store(CategoryRequest $request, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save(new Category, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category created.']);

        return to_route('admin.categories.index');
    }

    public function edit(Category $category, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form($category, 'categories'));
    }

    public function update(CategoryRequest $request, Category $category, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save($category, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category updated.']);

        return to_route('admin.categories.index');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages(['delete' => 'This category is used by products. Reassign those products before deleting it.']);
        }
        if ($category->children()->exists()) {
            throw ValidationException::withMessages(['delete' => 'Reassign or remove child categories before deleting this category.']);
        }

        try {
            DB::transaction(fn () => $category->delete());
        } catch (QueryException $exception) {
            // PostgreSQL and SQLite foreign-key violations; unrelated database errors must surface.
            if (in_array((string) $exception->getCode(), ['23503', '23000'], true)) {
                throw ValidationException::withMessages(['delete' => 'This category is referenced and cannot be deleted.']);
            }
            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category deleted.']);

        return to_route('admin.categories.index');
    }
}
