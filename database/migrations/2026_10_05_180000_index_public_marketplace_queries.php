<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->index(['status', 'is_active', 'is_featured', 'id'], 'products_storefront'));
        Schema::table('product_offers', fn (Blueprint $t) => $t->index(['product_id', 'is_active', 'price'], 'offers_storefront_price'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX products_business_search ON products USING GIN (to_tsvector('simple', coalesce(name,'') || ' ' || coalesce(short_description,'') || ' ' || coalesce(business_problem,'') || ' ' || coalesce(target_audience::text,'')))");
            DB::statement('CREATE INDEX products_name_prefix ON products (lower(name) text_pattern_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX products_business_search');
            DB::statement('DROP INDEX products_name_prefix');
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropIndex('products_storefront'));
        Schema::table('product_offers', fn (Blueprint $t) => $t->dropIndex('offers_storefront_price'));
    }
};
