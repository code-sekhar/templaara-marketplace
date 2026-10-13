<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\DevelopmentGateway;
use App\Services\Commerce\PaymentLifecycle;

class EntitlementFixtures
{
    public static function order(bool $paid = true, ?User $buyer = null): Order
    {
        $buyer ??= User::factory()->create()->assignRole('customer');
        $product = CommerceFixtures::product();
        app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
        $order = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->first()->checkout_key, CommerceFixtures::billing());
        if ($paid) {
            self::pay($order);
        }

        return $order->fresh();
    }

    public static function pay(Order $order, string $outcome = 'paid'): Order
    {
        return app(PaymentLifecycle::class)->process(app(DevelopmentGateway::class)->simulate($order->payments()->latest('id')->firstOrFail(), $outcome));
    }
}
