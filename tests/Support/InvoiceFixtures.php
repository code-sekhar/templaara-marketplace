<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Invoicing\CheckoutTax;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Settings\UpdateMarketplaceSettings;

final class InvoiceFixtures
{
    public static function configure(array $overrides = []): User
    {
        $admin = User::factory()->create()->assignRole('admin');
        $values = ['enabled' => true, 'legal_name' => 'Templaara Test Company', 'trading_name' => 'Templaara', 'address_line_1' => '10 Platform Road', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500001', 'contact_email' => 'billing@example.test', ...$overrides];
        $section = app(MarketplaceSettings::class)->section('invoicing', true);
        app(UpdateMarketplaceSettings::class)->save($admin, 'invoicing', ['values' => $values, 'versions' => array_combine(array_keys($values), array_map(fn ($key) => $section[$key]['version'], array_keys($values)))]);

        return $admin;
    }

    public static function order(array $policy = [], string $price = '100.00', string $currency = 'INR', bool $author = false): Order
    {
        self::configure($policy);
        $owner = $author ? User::factory()->create()->assignRole('author') : null;
        $product = $author ? EarningFixtures::product($owner, $price, $currency) : CommerceFixtures::product(['currency' => $currency]);
        if (! $author) {
            $product->offers()->first()->forceFill(['price' => $price])->save();
        }
        $buyer = User::factory()->create()->assignRole('customer');
        app(CartService::class)->add($buyer, $product->slug, $product->offers()->first()->id);
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $billing = [...CommerceFixtures::billing(), 'country_code' => 'IN', 'gst_state_code' => '36', 'state_region' => 'Telangana', 'customer_tax_id' => 'BUSINESS-TEST'];
        $quote = app(CheckoutTax::class)->quote([$price], $billing);
        $billing['tax_quote_hash'] = app(CheckoutTax::class)->hash($quote, $billing, $cart->checkout_key);

        return app(Checkout::class)->place($buyer, $cart->checkout_key, $billing);
    }

    public static function paid(array $policy = [], string $price = '100.00', string $currency = 'INR', bool $author = false): Order
    {
        return EntitlementFixtures::pay(self::order($policy, $price, $currency, $author))->fresh();
    }

    public static function gst(bool $inclusive = false): array
    {
        return ['tax_mode' => 'gst', 'gstin' => '36ABCDE1234F1Z5', 'gst_state_code' => '36', 'tax_rate' => '18.00', 'policy_reviewed' => true, 'prices_include_tax' => $inclusive];
    }
}
