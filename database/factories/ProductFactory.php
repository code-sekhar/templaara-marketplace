<?php

namespace Database\Factories;

use App\Enums\ProductCurrency;
use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'category_id' => Category::factory(),
            'origin' => ProductOrigin::Author,
            'status' => ProductStatus::Draft,
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'target_audience' => ['Retail businesses'],
            'key_benefits' => ['Simpler daily operations'],
            'currency' => ProductCurrency::INR,
            'is_active' => true,
            'is_featured' => false,
        ];
    }

    public function official(): static
    {
        return $this->state(fn () => ['owner_id' => null, 'origin' => ProductOrigin::Templaara]);
    }
}
