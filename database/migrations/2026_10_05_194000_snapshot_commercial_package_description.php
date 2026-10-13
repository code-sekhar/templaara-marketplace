<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->text('offer_description')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Commercial snapshot fields must not be removed.');
    }
};
