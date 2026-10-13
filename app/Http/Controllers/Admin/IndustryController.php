<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\IndustryRequest;
use App\Models\Industry;
use App\Services\Catalog\CatalogListing;
use App\Services\Catalog\SaveCatalogRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IndustryController extends Controller
{
    public function index(Request $request, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Index', $listing->admin($request, new Industry, 'industries'));
    }

    public function create(CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form(new Industry, 'industries'));
    }

    public function store(IndustryRequest $request, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save(new Industry, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Industry created.']);

        return to_route('admin.industries.index');
    }

    public function edit(Industry $industry, CatalogListing $listing): Response
    {
        return Inertia::render('admin/catalog/Form', $listing->form($industry, 'industries'));
    }

    public function update(IndustryRequest $request, Industry $industry, SaveCatalogRecord $save): RedirectResponse
    {
        $save->save($industry, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Industry updated.']);

        return to_route('admin.industries.index');
    }

    public function destroy(Industry $industry): RedirectResponse
    {
        if ($industry->products()->exists()) {
            throw ValidationException::withMessages(['delete' => 'This industry is used by products. Reassign those products before deleting it.']);
        }

        try {
            DB::transaction(fn () => $industry->delete());
        } catch (QueryException $exception) {
            // PostgreSQL and SQLite foreign-key violations; unrelated database errors must surface.
            if (in_array((string) $exception->getCode(), ['23503', '23000'], true)) {
                throw ValidationException::withMessages(['delete' => 'This industry is referenced and cannot be deleted.']);
            }
            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Industry deleted.']);

        return to_route('admin.industries.index');
    }
}
