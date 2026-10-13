<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\TechnologyRequest;
use App\Models\Technology;
use App\Services\Catalog\CatalogListing;
use App\Services\Catalog\SaveCatalogRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TechnologyController extends Controller
{
    public function index(Request $request, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Index', $listing->admin($request, new Technology, 'technologies'));
    }

    public function create(CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form(new Technology, 'technologies'));
    }

    public function store(TechnologyRequest $request, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save(new Technology, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Technology created.']);

        return to_route('admin.technologies.index');
    }

    public function edit(Technology $technology, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form($technology, 'technologies'));
    }

    public function update(TechnologyRequest $request, Technology $technology, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save($technology, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Technology updated.']);

        return to_route('admin.technologies.index');
    }

    public function destroy(Technology $technology): RedirectResponse
    {
        if ($technology->products()->exists()) {
            throw ValidationException::withMessages(['delete' => 'This technology is used by products. Reassign those products before deleting it.']);
        }

        try {
            DB::transaction(fn () => $technology->delete());
        } catch (QueryException $exception) {
            // PostgreSQL and SQLite foreign-key violations; unrelated database errors must surface.
            if (in_array((string) $exception->getCode(), ['23503', '23000'], true)) {
                throw ValidationException::withMessages(['delete' => 'This technology is referenced and cannot be deleted.']);
            }
            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Technology deleted.']);

        return to_route('admin.technologies.index');
    }
}
