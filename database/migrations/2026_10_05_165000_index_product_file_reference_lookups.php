<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_submission_media', fn (Blueprint $table) => $table->index('product_media_id'));
        Schema::table('product_submission_artifacts', fn (Blueprint $table) => $table->index('product_artifact_id'));
        Schema::table('product_releases', fn (Blueprint $table) => $table->index('created_by'));
    }

    public function down(): void
    {
        Schema::table('product_submission_media', fn (Blueprint $table) => $table->dropIndex(['product_media_id']));
        Schema::table('product_submission_artifacts', fn (Blueprint $table) => $table->dropIndex(['product_artifact_id']));
        Schema::table('product_releases', fn (Blueprint $table) => $table->dropIndex(['created_by']));
    }
};
