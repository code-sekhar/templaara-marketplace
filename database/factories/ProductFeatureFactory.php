<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductFeature> */
class ProductFeatureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'title' => fake()->sentence(3), 'description' => fake()->sentence(), 'sort_order' => 0];
    }
}
