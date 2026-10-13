<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('origin', 20);
            $table->string('name');
            $table->string('slug', 200)->unique();
            $table->string('short_description', 500);
            $table->text('description')->nullable();
            $table->text('business_problem')->nullable();
            $table->jsonb('target_audience');
            $table->jsonb('key_benefits');
            $table->string('status', 30)->default('draft');
            $table->string('currency', 3)->default('INR');
            $table->string('demo_url', 2048)->nullable();
            $table->string('demo_username')->nullable();
            $table->string('demo_password')->nullable();
            $table->string('version_label', 100)->nullable();
            $table->string('documentation_url', 2048)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status', 'id']);
            $table->index(['category_id', 'status']);
            $table->index(['origin', 'status', 'id']);
            $table->index(['is_active', 'status']);
            $table->index('status');
        });

        Schema::create('product_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('license_type', 30)->default('single_business');
            $table->integer('support_period_days')->nullable();
            $table->integer('updates_period_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        foreach (['industry_product' => 'industries', 'product_technology' => 'technologies'] as $name => $catalog) {
            Schema::create($name, function (Blueprint $table) use ($catalog): void {
                $column = $catalog === 'industries' ? 'industry_id' : 'technology_id';
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId($column)->constrained($catalog)->restrictOnDelete();
                $table->timestamps();
                $table->primary(['product_id', $column]);
                $table->index($column);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_origin_owner CHECK ((origin = 'author' AND owner_id IS NOT NULL) OR (origin = 'templaara' AND owner_id IS NULL))");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_draft_status CHECK (status = 'draft')");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_currency CHECK (currency IN ('INR', 'USD', 'EUR', 'GBP'))");
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_price_nonnegative CHECK (price >= 0)');
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_periods_nonnegative CHECK ((support_period_days IS NULL OR support_period_days >= 0) AND (updates_period_days IS NULL OR updates_period_days >= 0) AND sort_order >= 0)');
            DB::statement("ALTER TABLE product_offers ADD CONSTRAINT product_offers_license_type CHECK (license_type IN ('single_business', 'multi_business', 'extended'))");
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_default_active CHECK (NOT is_default OR is_active)');
            DB::statement('ALTER TABLE product_features ADD CONSTRAINT product_features_sort_nonnegative CHECK (sort_order >= 0)');
            DB::statement('CREATE UNIQUE INDEX product_offers_one_default ON product_offers (product_id) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_technology');
        Schema::dropIfExists('industry_product');
        Schema::dropIfExists('product_offers');
        Schema::dropIfExists('product_features');
        Schema::dropIfExists('products');
    }
};
