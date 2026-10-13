<?php

namespace App\Http\Controllers;

use App\Enums\ProductOrigin;
use App\Enums\ProductWorkflowAction;
use App\Http\Requests\Products\Workflow\WorkflowRequest;
use App\Models\Product;
use App\Services\Products\Workflow\ProductWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProductWorkflowController extends Controller
{
    public function submit(WorkflowRequest $request, Product $product, ProductWorkflow $workflow): RedirectResponse
    {
        if ($request->routeIs('author.*')) {
            abort_unless($product->origin === ProductOrigin::Author && $product->owner_id === $request->user()->id, 403);
        } else {
            abort_unless($product->origin === ProductOrigin::Templaara, 403);
        }
        $workflow->transition($request->user(), $product, ProductWorkflowAction::Submit, input: $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product submitted for review.']);

        return to_route($request->routeIs('author.*') ? 'author.products.show' : 'admin.products.show', $product);
    }

    public function publish(WorkflowRequest $request, Product $product, ProductWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($request->user(), $product, ProductWorkflowAction::Publish, input: $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product marked Published. Storefront access will be added separately.']);

        return back();
    }
}
