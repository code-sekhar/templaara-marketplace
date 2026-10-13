<?php

namespace Tests\Support;

use App\Enums\ProductWorkflowAction;
use App\Models\Cart;
use App\Models\Industry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\Technology;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Products\Workflow\ProductWorkflow;

class EarningFixtures
{
    public static function product(?User $author = null, string $price = '5000.00', string $currency = 'INR'): Product
    {
        $author ??= User::factory()->create()->assignRole('author');
        $admin = User::factory()->create()->assignRole('admin');
        $p = Product::factory()->create(['owner_id' => $author->id, 'origin' => 'author', 'currency' => $currency, 'name' => 'Nexora Business Inventory', 'short_description' => 'Inventory and billing software for retail and wholesale businesses.', 'description' => 'Manage billing, purchases, stock and customer dues in one reliable business workspace.', 'business_problem' => 'Replace manual stock tracking and fragmented billing records.', 'demo_url' => 'https://demo.example.com']);
        $p->industries()->attach(Industry::factory()->create());
        $p->technologies()->attach(Technology::factory()->create());
        ProductFeature::factory()->for($p)->create();
        ProductOffer::factory()->for($p)->create(['price' => $price]);
        ProductFileFixtures::prepare($p, $author);
        $w = app(ProductWorkflow::class);
        $r = $w->transition($author, $p, ProductWorkflowAction::Submit);
        $w->transition($admin, $p, ProductWorkflowAction::StartReview, $r->id);
        $w->transition($admin, $p, ProductWorkflowAction::Approve, $r->id);
        $w->transition($admin, $p, ProductWorkflowAction::Publish);

        return $p->fresh();
    }

    public static function order(?Product $product = null, bool $paid = true): Order
    {
        $product ??= self::product();
        $buyer = User::factory()->create()->assignRole('customer');
        app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
        $order = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->first()->checkout_key, CommerceFixtures::billing());
        if ($paid) {
            EntitlementFixtures::pay($order);
        }

        return $order->fresh();
    }
}
