<?php

namespace Database\Factories;

use App\Enums\LicenseType;
use App\Models\Product;
use App\Models\ProductOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductOffer> */
class ProductOfferFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(), 'name' => 'Software License', 'price' => '4999.00',
            'license_type' => LicenseType::SingleBusiness, 'is_default' => true, 'is_active' => true, 'sort_order' => 0,
        ];
    }

    public function additional(): static
    {
        return $this->state(fn () => ['name' => 'Software + Installation', 'price' => '8999.00', 'is_default' => false, 'sort_order' => 10]);
    }
}
