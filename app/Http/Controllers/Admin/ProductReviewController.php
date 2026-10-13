<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductWorkflowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\Workflow\WorkflowRequest;
use App\Models\ProductSubmission;
use App\Services\Products\Files\FilePresentation;
use App\Services\Products\Workflow\ProductReviewWorkspace;
use App\Services\Products\Workflow\ProductWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductReviewController extends Controller
{
    public function index(Request $request, ProductReviewWorkspace $workspace): Response
    {
        return Inertia::render('product-reviews/Index', $workspace->queue($request));
    }

    public function show(Request $request, ProductSubmission $submission, ProductReviewWorkspace $workspace): Response
    {
        $submission->load('product');

        return Inertia::render('product-reviews/Show', [
            'submission' => $submission->only(['id', 'product_id', 'round_number', 'submitted_at', 'review_started_at', 'reviewed_by', 'decision', 'decision_at', 'decision_summary']),
            'product' => app(FilePresentation::class)->snapshot($submission->snapshot),
            'currentStatus' => $submission->product->status,
            'history' => $workspace->history($submission->product),
            'actions' => $workspace->actions($request, $submission->product, $submission),
        ]);
    }

    public function transition(WorkflowRequest $request, ProductSubmission $submission, ProductWorkflow $workflow): RedirectResponse
    {
        $action = ProductWorkflowAction::from($request->route('action'));
        $workflow->transition($request->user(), $submission->product, $action, $submission->id, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Review action recorded.']);

        return to_route('admin.product-reviews.show', $submission);
    }
}
