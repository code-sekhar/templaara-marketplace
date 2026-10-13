<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Reviews\ReviewWorkflow;

class ReviewFixtures
{
    public static function purchase(?Product $product = null, ?User $buyer = null, bool $paid = true): Order
    {
        $product ??= EarningFixtures::product();
        $buyer ??= User::factory()->create()->assignRole('customer');
        app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
        $o = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->value('checkout_key'), CommerceFixtures::billing());

        return $paid ? EntitlementFixtures::pay($o) : $o;
    }

    /** @return array<string,mixed> */
    public static function content(int $rating = 5): array
    {
        return ['rating' => $rating, 'title' => 'Useful business software', 'body' => 'This inventory workflow works very well for our retail business.'];
    }

    public static function review(?Order $order = null, int $rating = 5): ProductReview
    {
        $order ??= self::purchase();

        return app(ReviewWorkflow::class)->create(User::findOrFail($order->user_id), $order->items->first()->product, self::content($rating));
    }
}
