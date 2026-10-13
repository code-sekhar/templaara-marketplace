<?php

namespace Database\Seeders;

use App\Enums\LicenseType;
use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\Technology;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentProductSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Sample products are restricted to local and testing environments.');
        }

        $category = Category::where('slug', 'pos-inventory')->first();
        if (! $category || ! $category->is_active) {
            return;
        }

        DB::transaction(function () use ($category): void {
            if (Product::where('slug', 'nexora-inventory-pos')->exists()) {
                return;
            }
            $product = new Product;
            $product->fill([
                'category_id' => $category->id,
                'name' => 'Nexora Inventory & POS', 'slug' => 'nexora-inventory-pos',
                'short_description' => 'Inventory, billing and sales management for retail and wholesale businesses.',
                'description' => 'Manage daily billing, purchases, stock and customer dues from a single business workspace.',
                'business_problem' => 'Replace manual stock tracking, billing and fragmented sales records.',
                'target_audience' => ['Retail shops', 'Wholesalers', 'Distributors'],
                'key_benefits' => ['Real-time stock visibility', 'Faster billing', 'Clear business reports'],
                'currency' => 'INR',
            ]);
            $product->forceFill(['owner_id' => null, 'origin' => ProductOrigin::Templaara, 'status' => ProductStatus::Draft]);
            $product->save();
            $product->industries()->sync(Industry::active()->whereIn('slug', ['retail', 'wholesale-distribution'])->pluck('id'));
            $product->technologies()->sync(Technology::active()->whereIn('slug', ['laravel', 'vuejs', 'postgresql'])->pluck('id'));
            $product->features()->createMany([
                ['title' => 'Inventory tracking', 'description' => 'Track stock and purchases.', 'sort_order' => 0],
                ['title' => 'Fast billing', 'description' => 'Create bills and maintain customer dues.', 'sort_order' => 10],
            ]);
            $product->offers()->create([
                'name' => 'Software License', 'price' => '4999.00', 'license_type' => LicenseType::SingleBusiness,
                'support_period_days' => 180, 'updates_period_days' => 365, 'is_default' => true, 'is_active' => true, 'sort_order' => 0,
            ]);
        });
    }
}
