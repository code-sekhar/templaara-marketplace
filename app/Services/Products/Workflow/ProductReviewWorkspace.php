<?php

namespace App\Services\Products\Workflow;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductReviewWorkspace
{
    /** @return array<string, mixed> */
    public function queue(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'origin' => ['nullable', Rule::in(['author', 'templaara'])], 'category' => ['nullable', 'integer'],
            'author' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
        ]);
        $query = ProductSubmission::query()->whereNull('product_revision_id')->select(['id', 'product_id', 'round_number', 'submitted_by', 'submitted_at', 'reviewed_by', 'review_started_at', 'decision', 'decision_at'])
            ->whereRaw('round_number = (select max(s.round_number) from product_submissions s where s.product_id = product_submissions.product_id AND s.product_revision_id IS NULL)')
            ->with(['product:id,owner_id,category_id,name,slug,origin,status', 'product.owner:id,name', 'product.category:id,name', 'reviewer:id,name']);
        $query->whereHas('product', function ($query) use ($filters): void {
            foreach (['status' => 'status', 'origin' => 'origin', 'category' => 'category_id', 'author' => 'owner_id'] as $filter => $column) {
                if (! empty($filters[$filter])) {
                    $query->where($column, $filters[$filter]);
                }
            }
            if (! empty($filters['search'])) {
                $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
                $query->where(fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$term]));
            }
        });
        if (! empty($filters['from'])) {
            $query->whereDate('submitted_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('submitted_at', '<=', $filters['to']);
        }

        return ['records' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(15)->withQueryString(), 'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'authors' => User::whereHas('products')->orderBy('name')->get(['id', 'name'])];
    }

    /** @return array<string, mixed> */
    public function history(Product $product): array
    {
        $rounds = $product->submissions()->select(['id', 'product_id', 'round_number', 'submitted_by', 'submitted_at', 'reviewed_by', 'review_started_at', 'decision', 'decision_at', 'decision_summary'])
            ->with(['feedback.creator:id,name', 'submitter:id,name', 'reviewer:id,name'])->paginate(10, ['*'], 'rounds_page')->withQueryString();
        $events = $product->lifecycleEvents()->with('actor:id,name')->paginate(20, ['*'], 'events_page')->withQueryString();

        return ['rounds' => $rounds, 'events' => $events];
    }

    /** @return array<string, mixed> */
    public function actions(Request $request, Product $product, ?ProductSubmission $submission = null): array
    {
        $user = $request->user();
        $current = $submission === null || $product->submissions()->whereNull('product_revision_id')->first()?->id === $submission->id;
        $claimed = $submission?->reviewed_by === $user->id;

        return [
            'submit' => $user->can('submit', $product) && in_array($product->status, [ProductStatus::Draft, ProductStatus::ChangesRequested], true),
            'start' => $current && $user->can('startReview', $product) && $product->status === ProductStatus::Submitted,
            'changes' => $current && $claimed && $user->can('requestChanges', $product) && $product->status === ProductStatus::UnderReview,
            'reject' => $current && $claimed && $user->can('reject', $product) && $product->status === ProductStatus::UnderReview,
            'approve' => $current && $claimed && $user->can('approve', $product) && $product->status === ProductStatus::UnderReview,
            'publish' => $current && $user->can('publish', $product) && $product->status === ProductStatus::Approved,
        ];
    }
}
