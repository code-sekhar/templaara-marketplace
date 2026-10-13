<?php

namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Reviews\ProductRatingSummary;
use App\Services\Reviews\ReviewEligibility;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Reviews\ReviewWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function create(Request $request, Product $product, ReviewEligibility $eligibility): Response|RedirectResponse
    {
        $old = ProductReview::where('customer_user_id', $request->user()->id)->where('product_id', $product->id)->first();
        if ($old) {
            return to_route('account.reviews.show', $old);
        }
        abort_unless($eligibility->purchase($request->user(), $product) !== null, 403, 'A qualifying paid purchase is required.');

        return Inertia::render('reviews/Form', ['product' => $product->only(['name', 'slug']), 'review' => null, 'admin' => false, 'author' => false]);
    }

    public function store(Request $request, Product $product, ReviewWorkflow $workflow): RedirectResponse
    {
        $r = $workflow->create($request->user(), $product, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your verified-purchase review is published.']);

        return to_route('account.reviews.show', $r);
    }

    public function show(Request $request, ProductReview $review, ReviewPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        $author = $request->routeIs('author.*');
        if ($author) {
            abort_unless($review->product->owner_id === $request->user()->id && app(ProductRatingSummary::class)->publicQuery()->whereKey($review->id)->exists(), 404);
        }
        if (! $admin && ! $author) {
            abort_unless($review->getAttribute('customer_user_id') === $request->user()->id, 404);
        }

        return Inertia::render('reviews/Form', ['product' => $review->product->only(['name', 'slug']), 'review' => $view->privateRow($review, $request->user(), $admin, $author), 'admin' => $admin, 'author' => $author]);
    }

    public function action(Request $request, ProductReview $review, ReviewWorkflow $workflow): RedirectResponse
    {
        $workflow->action($request->user(), $review, $request->route('action'), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Review updated.']);

        return back();
    }

    public function index(Request $request, ReviewPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        $author = $request->routeIs('author.*');
        $filters = $request->validate(['status' => ['nullable', 'in:published,hidden,withdrawn,unqualified'], 'rating' => ['nullable', 'integer', 'between:1,5'], 'product' => ['nullable', 'string', 'max:200'], 'customer' => ['nullable', 'string', 'max:100'], 'author' => ['nullable', 'string', 'max:100'], 'response' => ['nullable', 'in:answered,unanswered'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100']]);
        $q = $author ? app(ProductRatingSummary::class)->publicQuery()->whereHas('product', fn ($q) => $q->where('owner_id', $request->user()->id)) : ProductReview::query();
        if (! $admin && ! $author) {
            $q->where('customer_user_id', $request->user()->id);
        }
        if (! empty($filters['rating'])) {
            $q->where('rating', $filters['rating']);
        }
        if (! empty($filters['status'])) {
            match ($filters['status']) {
                'withdrawn' => $q->whereNotNull('withdrawn_at'),'unqualified' => $q->where('is_verified', false),default => $q->where('status', $filters['status'])
            };
        }
        if (! empty($filters['product'])) {
            $q->whereHas('product', fn ($q) => $q->where('slug', $filters['product']));
        }
        foreach (['customer', 'author'] as $k) {
            if ($admin && ! empty($filters[$k])) {
                $term = '%'.addcslashes(mb_strtolower($filters[$k]), '%_\\').'%';
                $q->whereHas($k === 'customer' ? 'customer' : 'product.owner', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]));
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $q->where(fn ($q) => $q->whereRaw('LOWER(body) LIKE ?', [$term])->orWhereRaw('LOWER(title) LIKE ?', [$term])->orWhereHas('product', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])));
        }
        if (! empty($filters['response'])) {
            $filters['response'] === 'answered' ? $q->has('response') : $q->doesntHave('response');
        }
        if (! empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['until'])) {
            $q->whereDate('created_at', '<=', $filters['until']);
        }
        $records = $q->with(['product', 'customer', 'purchase', 'response.author'])->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($r) => [...$view->row($r), 'verified_purchase' => null, 'product' => $r->product->only(['name', 'slug']), 'status' => $r->status->value, 'withdrawn_at' => $r->getAttribute('withdrawn_at'), 'verification_retained' => $r->getAttribute('is_verified')]);

        return Inertia::render('reviews/Index', ['records' => $records, 'filters' => $filters, 'admin' => $admin, 'author' => $author]);
    }
}
