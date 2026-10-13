<?php

namespace Tests\Support;

use App\Enums\ProductWorkflowAction;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\Technology;
use App\Models\User;
use App\Services\Products\Workflow\ProductWorkflow;

final class CommerceFixtures
{
    /** @param array<string,mixed> $attributes */
    public static function product(array $attributes = []): Product
    {
        $admin = User::factory()->create()->assignRole('admin');
        $product = Product::factory()->official()->create([
            'name' => 'Nexora Inventory & POS', 'short_description' => 'Inventory and billing software for retail and wholesale businesses.',
            'description' => 'Manage billing, purchases, stock and customer dues in one reliable business workspace.',
            'business_problem' => 'Replace manual stock tracking and fragmented billing records.', 'demo_url' => 'https://demo.example.com', ...$attributes]);
        $product->industries()->attach(Industry::factory()->create());
        $product->technologies()->attach(Technology::factory()->create());
        ProductFeature::factory()->for($product)->create();
        ProductOffer::factory()->for($product)->create(['support_period_days' => 90, 'updates_period_days' => 365]);
        ProductFileFixtures::prepare($product, $admin);
        $workflow = app(ProductWorkflow::class);
        $round = $workflow->transition($admin, $product, ProductWorkflowAction::Submit);
        $workflow->transition($admin, $product, ProductWorkflowAction::StartReview, $round->id);
        $workflow->transition($admin, $product, ProductWorkflowAction::Approve, $round->id);
        $workflow->transition($admin, $product, ProductWorkflowAction::Publish);

        return $product->fresh();
    }

    /** @return array<string,string> */
    public static function billing(): array
    {
        return ['customer_name' => 'Business Customer', 'company_name' => 'Business Example', 'billing_email' => 'billing@example.com', 'phone' => '+1 212 555 0100',
            'address_line_1' => '10 Market Street', 'address_line_2' => 'Suite 3', 'city' => 'New York', 'state_region' => 'NY', 'postal_code' => '10001', 'country_code' => 'US'];
    }
}
