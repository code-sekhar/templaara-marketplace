<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->string('preview_video_url', 2048)->nullable());
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->integer('width');
            $table->integer('height');
            $table->boolean('is_current')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('alt_text', 500)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path']);
            $table->index(['product_id', 'is_current', 'type', 'sort_order']);
        });
        Schema::create('product_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('version', 50);
            $table->string('status', 20)->default('draft');
            $table->text('changelog');
            $table->text('release_notes')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'version']);
            $table->index(['product_id', 'status', 'id']);
        });
        Schema::create('product_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_release_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->string('checksum', 64);
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->unique(['disk', 'path']);
            $table->index(['product_release_id', 'type', 'is_current']);
        });
        foreach (['media' => 'product_media', 'artifacts' => 'product_artifacts'] as $suffix => $referenced) {
            Schema::create('product_submission_'.$suffix, function (Blueprint $table) use ($suffix, $referenced): void {
                $table->id();
                $table->foreignId('product_submission_id')->constrained()->restrictOnDelete();
                $table->foreignId($suffix === 'media' ? 'product_media_id' : 'product_artifact_id')->constrained($referenced)->restrictOnDelete();
                $table->timestamps();
                $table->unique(['product_submission_id', $suffix === 'media' ? 'product_media_id' : 'product_artifact_id']);
            });
        }
        Schema::create('product_file_cleanup', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->timestampTz('available_at');
            $table->timestamps();
            $table->unique(['disk', 'path']);
            $table->index('available_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE product_media ADD CONSTRAINT product_media_values CHECK (type IN ('cover','screenshot') AND size_bytes > 0 AND width > 0 AND height > 0 AND sort_order >= 0)");
            DB::statement("CREATE UNIQUE INDEX product_media_current_cover ON product_media(product_id) WHERE type = 'cover' AND is_current");
            DB::statement("ALTER TABLE product_artifacts ADD CONSTRAINT product_artifact_values CHECK (type IN ('source_package','documentation') AND size_bytes > 0 AND checksum ~ '^[a-f0-9]{64}$')");
            DB::statement('CREATE UNIQUE INDEX product_artifact_current_type ON product_artifacts(product_release_id,type) WHERE is_current');
            DB::statement("ALTER TABLE product_releases ADD CONSTRAINT product_release_values CHECK (status IN ('draft','in_review','approved','published','rejected') AND version ~ '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$')");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_product_file_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME IN ('product_submission_media','product_submission_artifacts') THEN
        RAISE EXCEPTION 'Submission file references are immutable';
    END IF;
    IF (NEW.disk,NEW.path,NEW.original_name,NEW.mime_type,NEW.size_bytes) IS DISTINCT FROM
       (OLD.disk,OLD.path,OLD.original_name,OLD.mime_type,OLD.size_bytes) THEN
        RAISE EXCEPTION 'Stored file identity is immutable; upload a replacement';
    END IF;
    IF TG_TABLE_NAME = 'product_artifacts' THEN
        IF NEW.checksum <> OLD.checksum OR NEW.product_release_id <> OLD.product_release_id OR NEW.type <> OLD.type THEN
            RAISE EXCEPTION 'Artifact identity is immutable';
        END IF;
    ELSE
        IF NEW.width <> OLD.width OR NEW.height <> OLD.height OR NEW.product_id <> OLD.product_id OR NEW.type <> OLD.type THEN
            RAISE EXCEPTION 'Media identity is immutable';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER product_media_identity BEFORE UPDATE ON product_media FOR EACH ROW EXECUTE FUNCTION templaara_product_file_guard();
CREATE TRIGGER product_artifact_identity BEFORE UPDATE ON product_artifacts FOR EACH ROW EXECUTE FUNCTION templaara_product_file_guard();
CREATE TRIGGER product_submission_media_immutable BEFORE UPDATE OR DELETE ON product_submission_media FOR EACH ROW EXECUTE FUNCTION templaara_product_file_guard();
CREATE TRIGGER product_submission_artifacts_immutable BEFORE UPDATE OR DELETE ON product_submission_artifacts FOR EACH ROW EXECUTE FUNCTION templaara_product_file_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('product_submission_media')->exists() || DB::table('product_submission_artifacts')->exists()) {
            throw new RuntimeException('Cannot remove retained submission file references.');
        }
        foreach (['product_submission_artifacts', 'product_submission_media', 'product_artifacts', 'product_releases', 'product_media', 'product_file_cleanup'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION templaara_product_file_guard()');
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('preview_video_url'));
    }
};
