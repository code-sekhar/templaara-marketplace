<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'industries', 'technologies'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('name');
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                if ($name === 'categories') {
                    $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
                    $table->index('parent_id');
                }
                if ($name === 'technologies') {
                    $table->string('logo')->nullable();
                    $table->string('website_url', 2048)->nullable();
                } else {
                    $table->string('icon')->nullable();
                }
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['is_active', 'sort_order', 'name']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id)');
            foreach (['categories', 'industries', 'technologies'] as $table) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_sort_order_nonnegative CHECK (sort_order >= 0)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technologies');
        Schema::dropIfExists('industries');
        Schema::dropIfExists('categories');
    }
};
