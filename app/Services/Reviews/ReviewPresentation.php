<?php

namespace App\Services\Reviews;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ReviewPresentation
{
    /**
     * @return array<string,mixed> */
    public function row(ProductReview $r): array
    {
        $name = (preg_split('/\s+/u', trim($r->customer->name)) ?: []);
        $display = $name[0] ?? '';
        if (count($name) > 1) {
            $display .= ' '.mb_substr(end($name), 0, 1).'.';
        }
        if (str_contains($display, '@')) {
            $display = 'Verified customer';
        }

        return ['uuid' => $r->getAttribute('public_uuid'), 'rating' => $r->getAttribute('rating'), 'title' => $r->getAttribute('title'), 'body' => $r->getAttribute('body'), 'reviewer' => mb_substr($display, 0, 100), 'verified_purchase' => true, 'purchased_version' => $r->purchase->getAttribute('version'), 'published_at' => $r->getAttribute('published_at'), 'edited_at' => $r->getAttribute('edited_at'), 'response' => $r->response ? ['body' => $r->response->getAttribute('body'), 'seller' => $r->product->origin->value === 'templaara' ? 'Templaara' : $r->response->author->name, 'published_at' => $r->response->getAttribute('published_at'), 'edited_at' => $r->response->getAttribute('edited_at')] : null];
    }

    /**
     * @return array<string,mixed> */
    public function privateRow(ProductReview $r, User $actor, bool $admin = false, bool $author = false): array
    {
        $r->loadMissing(['product', 'customer', 'purchase', 'response.author']);
        $data = $this->row($r);
        $eligible = app(ReviewEligibility::class)->purchase($r->customer, $r->product) !== null;
        $visible = app(ProductRatingSummary::class)->publicQuery()->whereKey($r->id)->exists();
        $owner = $r->getAttribute('customer_user_id') === $actor->id;

        return [...$data, 'verified_purchase' => $visible, 'product' => $r->product->only(['name', 'slug']), 'status' => $r->status->value, 'withdrawn_at' => $r->getAttribute('withdrawn_at'), 'qualified' => $eligible, 'verification_retained' => $r->getAttribute('is_verified'), 'can_edit' => $owner && $eligible && ! $r->getAttribute('withdrawn_at'), 'can_restore' => $owner && $eligible && ($r->getAttribute('withdrawn_at') || ! $r->getAttribute('is_verified')), 'can_withdraw' => $owner && ! $r->getAttribute('withdrawn_at'), 'can_respond' => ($admin || $author) && $visible && Gate::forUser($actor)->allows('respond', $r), 'can_edit_response' => ($admin || $author) && $visible && Gate::forUser($actor)->allows('respond', $r) && $r->response?->getAttribute('author_user_id') === $actor->id, 'events' => ($admin || $owner) ? $r->events()->orderByDesc('sequence')->paginate(20, ['type', 'reason', 'occurred_at'], 'history_page')->withQueryString() : null];
    }

    /** @param Collection<int,Product> $products
     * @return array<int,array<string,mixed>> */
    public function entries(?User $user, Collection $products): array
    {
        if (! $user) {
            return $products->mapWithKeys(fn ($p) => [$p->id => ['eligible' => false, 'url' => null, 'label' => null]])->all();
        }
        $ids = $products->pluck('id')->all();
        $eligible = app(ReviewEligibility::class)->query()->where('ro.user_id', $user->id)->whereIn('ri.product_id', $ids)->pluck('ri.product_id')->all();
        $old = ProductReview::where('customer_user_id', $user->id)->whereIn('product_id', $ids)->get()->keyBy('product_id');

        return $products->mapWithKeys(function ($p) use ($eligible, $old) {
            $r = $old->get($p->id);
            $can = in_array($p->id, $eligible, true);

            return [$p->id => ['eligible' => $can, 'url' => $r ? route('account.reviews.show', $r) : ($can ? route('account.reviews.create', $p->slug) : null), 'label' => $r ? 'Your Review' : 'Write a Review']];
        })->all();
    }

    /**
     * @return array<string,mixed> */
    public function entry(?User $user, Product $product): array
    {
        if (! $user) {
            return ['eligible' => false, 'url' => null, 'label' => null];
        }
        $old = ProductReview::where('customer_user_id', $user->id)->where('product_id', $product->id)->first();
        $eligible = app(ReviewEligibility::class)->purchase($user, $product) !== null;

        return ['eligible' => $eligible, 'url' => $old ? route('account.reviews.show', $old) : ($eligible ? route('account.reviews.create', $product->slug) : null), 'label' => $old ? 'Your Review' : 'Write a Review'];
    }
}
