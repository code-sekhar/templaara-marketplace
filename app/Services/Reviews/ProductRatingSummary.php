<?php

namespace App\Services\Reviews;

use App\Models\ProductReview;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

class ProductRatingSummary
{
    /**
     * @return Builder<ProductReview> */
    public function publicQuery(): Builder
    {
        $valid = app(ReviewEligibility::class)->query()->selectRaw('1')->whereColumn('ri.id', 'product_reviews.verified_order_item_id')->whereColumn('ri.product_id', 'product_reviews.product_id')->whereColumn('ro.user_id', 'product_reviews.customer_user_id');

        return ProductReview::where('product_reviews.status', 'published')->whereNull('product_reviews.withdrawn_at')->where('product_reviews.is_verified', true)->whereExists($valid);
    }

    /**
     * @return array{average:?string,count:int} */
    public function totals(int $count, string $sum): array
    {
        return ['average' => $count ? (string) BigDecimal::of($sum)->dividedBy((string) $count, 2, RoundingMode::HalfUp) : null, 'count' => $count];
    }

    /** @param array<int,int> $products
     * @return array<int,array<string,mixed>> */
    public function forProducts(array $products): array
    {
        $out = [];
        foreach ($products as $id) {
            $out[$id] = ['average' => null, 'count' => 0, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
        }
        $rows = $this->publicQuery()->whereIn('product_id', $products)->selectRaw('product_id,COUNT(*) AS reviews_count,SUM(rating) AS rating_total, SUM(CASE WHEN rating=1 THEN 1 ELSE 0 END) AS one,SUM(CASE WHEN rating=2 THEN 1 ELSE 0 END) AS two,SUM(CASE WHEN rating=3 THEN 1 ELSE 0 END) AS three,SUM(CASE WHEN rating=4 THEN 1 ELSE 0 END) AS four,SUM(CASE WHEN rating=5 THEN 1 ELSE 0 END) AS five')->groupBy('product_id')->get();
        foreach ($rows as $r) {
            $out[$r->getAttribute('product_id')] = ['average' => (string) BigDecimal::of((string) $r->getAttribute('rating_total'))->dividedBy((string) $r->getAttribute('reviews_count'), 2, RoundingMode::HalfUp), 'count' => (int) $r->getAttribute('reviews_count'), 'distribution' => [1 => (int) $r->getAttribute('one'), 2 => (int) $r->getAttribute('two'), 3 => (int) $r->getAttribute('three'), 4 => (int) $r->getAttribute('four'), 5 => (int) $r->getAttribute('five')]];
        }

        return $out;
    }
}
