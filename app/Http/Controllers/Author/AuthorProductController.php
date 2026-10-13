<?php

namespace App\Http\Controllers\Author;

use App\Enums\ProductOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ProductRequest;
use App\Models\Product;
use App\Services\Products\Files\DeleteProduct;
use App\Services\Products\ProductEditor;
use App\Services\Products\SaveProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthorProductController extends Controller
{
    public function index(Request $request, ProductEditor $editor): Response
    {
        return Inertia::render('products/Index', $editor->listing($request, false));
    }

    public function create(ProductEditor $editor): Response
    {
        return Inertia::render('products/Form', $editor->form(null, false));
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->create($request->user(), $request->validated(), ProductOrigin::Author);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft product created.']);

        return to_route('author.products.edit', $product);
    }

    public function show(Product $product, ProductEditor $editor): Response
    {
        return Inertia::render('products/Form', $editor->form($product, false, true));
    }

    public function edit(Product $product, ProductEditor $editor): Response
    {
        return Inertia::render('products/Form', $editor->form($product, false));
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save->update($request->user(), $product, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft product updated.']);

        return to_route('author.products.edit', $product);
    }

    public function destroy(Product $product): RedirectResponse
    {
        app(DeleteProduct::class)->delete(request()->user(), $product);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft product deleted.']);

        return to_route('author.products.index');
    }
}
