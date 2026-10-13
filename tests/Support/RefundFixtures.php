<?php

namespace Tests\Support;

use App\Models\AuthorEarning;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Refunds\RefundWorkflow;
use Illuminate\Support\Str;

class RefundFixtures
{
    public static function order(bool $available = false, string $currency = 'INR'): Order
    {
        $p = EarningFixtures::product(null, '5000.00', $currency);
        $o = EarningFixtures::order($p);
        if ($available) {
            app(ReleaseEarnings::class)->release(AuthorEarning::where('order_id', $o->id)->firstOrFail());
        }

        return $o;
    }

    /** @return array<string,mixed> */
    public static function input(Order $o, ?array $ids = null): array
    {
        return ['items' => $ids ?? $o->items()->pluck('id')->all(), 'reason_code' => 'technical_issue', 'customer_reason' => 'Product does not meet our business needs.', 'request_key' => (string) Str::uuid()];
    }

    public static function request(?Order $o = null): Refund
    {
        $o ??= self::order();

        return app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, self::input($o));
    }

    public static function process(Refund $r, ?User $admin = null): User
    {
        $admin ??= User::factory()->create()->assignRole('admin');
        app(RefundWorkflow::class)->transition($admin, $r, 'approve', admin: true);
        app(RefundWorkflow::class)->transition($admin, $r, 'process', admin: true);

        return $admin;
    }

    public static function complete(Refund $r, ?User $admin = null): User
    {
        $admin = self::process($r, $admin);
        app(RefundWorkflow::class)->transition($admin, $r, 'complete', ['external_reference' => 'EXTERNAL-REFUND-TEST'], true);

        return $admin;
    }

    public static function mixed(): Order
    {
        $buyer = User::factory()->create()->assignRole('customer');
        $products = [EarningFixtures::product(), EarningFixtures::product(), CommerceFixtures::product()];
        foreach ($products as $p) {
            app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
        }
        $o = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->first()->checkout_key, CommerceFixtures::billing());

        return EntitlementFixtures::pay($o)->fresh();
    }
}
