<?php

namespace App\Http\Controllers;

use App\Enums\ProductWorkflowAction;
use App\Http\Requests\Products\RevisionRequest;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Services\Products\Files\FilePresentation;
use App\Services\Products\ProductEditor;
use App\Services\Products\ProductUpdates;
use App\Services\Products\RevisionContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductUpdateController extends Controller
{
    public function create(Request $request, Product $product, ProductUpdates $updates): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'max:50']]);
        $r = $updates->create($request->user(), $product, $data['version']);

        return redirect()->route('products.updates.show', [$product, $r]);
    }

    public function show(Product $product, ProductRevision $revision, ProductEditor $editor): Response
    {
        abort_unless($revision->product_id === $product->id, 404);
        Gate::authorize('view', $revision);
        $projection = app(RevisionContent::class)->projection($revision);
        $props = $editor->form($product, request()->user()->can('manageAll', Product::class), ! request()->user()->can('update', $revision));
        $latest = $revision->submissions()->first();
        $snapshot = $latest?->snapshot;
        $data = [...$projection->attributesToArray(), 'features' => $projection->features->toArray(), 'offers' => $projection->offers->toArray(), 'industries' => $projection->industries->toArray(), 'technologies' => $projection->technologies->toArray(), 'category' => $projection->category->toArray(), 'owner' => $projection->owner?->only(['id', 'name'])];
        $props['product'] = $data;
        $props['history'] = null;
        $props['workflowActions'] = [];
        $props['canFeature'] = false;
        $props['canEditFiles'] = false;

        return Inertia::render('products/Update', ['editor' => $props, 'revision' => $revision->only(['id', 'product_id', 'status', 'proposed_release_id']), 'latestSubmissionId' => $latest?->id, 'liveVersion' => app(ProductUpdates::class)->current($product)->version, 'release' => app(FilePresentation::class)->release($revision->release->load('artifacts')), 'media' => $revision->media()->orderBy('sort_order')->get()->map(fn ($m) => app(FilePresentation::class)->media($m))->all(), 'snapshot' => $snapshot ? app(FilePresentation::class)->snapshot($snapshot) : null, 'rounds' => $revision->submissions()->with(['feedback', 'reviewer:id,name'])->paginate(10)->withQueryString(), 'actions' => $this->actions($revision)]);
    }

    public function save(RevisionRequest $request, Product $product, ProductRevision $revision, ProductUpdates $updates): RedirectResponse
    {
        abort_unless($revision->product_id === $product->id, 404);
        $updates->save($request->user(), $revision, $request->validated());

        return $this->done();
    }

    public function release(Request $request, Product $product, ProductRevision $revision, ProductUpdates $updates): RedirectResponse
    {
        abort_unless($revision->product_id === $product->id, 404);
        $updates->release($request->user(), $revision, $request->all());

        return $this->done();
    }

    public function upload(Request $request, Product $product, ProductRevision $revision, ProductUpdates $updates): RedirectResponse
    {
        abort_unless($revision->product_id === $product->id, 404);
        $request->validate(['file' => ['required', 'file'], 'kind' => ['required', 'in:cover,screenshot,source_package,documentation']]);
        $updates->upload($request->user(), $revision, $request->input('kind'), $request->file('file'));

        return $this->done();
    }

    public function remove(Request $request, Product $product, ProductRevision $revision, int $media, ProductUpdates $updates): RedirectResponse
    {
        abort_unless($revision->product_id === $product->id, 404);
        $updates->removeMedia($request->user(), $revision, $media);

        return $this->done();
    }

    public function transition(Request $request, Product $product, ProductRevision $revision, ProductUpdates $updates): RedirectResponse
    {
        abort_unless($revision->product_id === $product->id, 404);
        $updates->transition($request->user(), $revision, ProductWorkflowAction::from($request->route('action')), $request->all(), $request->integer('round_id') ?: null);

        return $this->done();
    }

    public function queue(Request $request): Response
    {
        Gate::authorize('reviewAny', Product::class);
        $data = $request->validate(['status' => ['nullable', 'in:submitted,under_review,changes_requested,approved,rejected,published'], 'search' => ['nullable', 'string', 'max:100']]);
        $q = ProductRevision::with(['product:id,name,origin', 'release:id,version'])->whereNotIn('status', ['draft']);
        if (! empty($data['status'])) {
            $q->where('status', $data['status']);
        }if (! empty($data['search'])) {
            $q->whereHas('product', fn ($q) => $q->where('name', 'like', '%'.addcslashes($data['search'], '%_\\').'%'));
        }

        return Inertia::render('products/Updates', ['records' => $q->orderByDesc('updated_at')->paginate(15)->withQueryString(), 'filters' => $data]);
    }

    /** @return array<string,bool> */
    private function actions(ProductRevision $r): array
    {
        $u = request()->user();
        $round = $r->submissions()->first();
        $p = $r->product;
        $claimed = $round?->reviewed_by === $u->id;
        $state = $r->status->value;

        return ['edit' => $u->can('update', $r), 'submit' => $u->can('submit', $r), 'start' => $state === 'submitted' && $u->can('startReview', $p), 'changes' => $state === 'under_review' && $claimed && $u->can('requestChanges', $p), 'reject' => $state === 'under_review' && $claimed && $u->can('reject', $p), 'approve' => $state === 'under_review' && $claimed && $u->can('approve', $p), 'publish' => $state === 'approved' && $u->can('publish', $p)];
    }

    private function done(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product update saved.']);

        return back();
    }
}
