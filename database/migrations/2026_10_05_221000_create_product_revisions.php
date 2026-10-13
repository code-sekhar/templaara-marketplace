<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('base_release_id')->constrained('product_releases')->restrictOnDelete();
            $t->foreignId('proposed_release_id')->unique()->constrained('product_releases')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 30)->default('draft');
            $t->jsonb('content');
            $t->timestampsTz();
            $t->index(['product_id', 'status']);
        });
        Schema::create('product_revision_media', function (Blueprint $t) {
            $t->foreignId('product_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_media_id')->constrained('product_media')->restrictOnDelete();
            $t->primary(['product_revision_id', 'product_media_id']);
        });
        Schema::table('product_submissions', fn (Blueprint $t) => $t->foreignId('product_revision_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('product_lifecycle_events', fn (Blueprint $t) => $t->foreignId('product_revision_id')->nullable()->constrained()->restrictOnDelete());
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX one_open_product_revision ON product_revisions(product_id) WHERE status NOT IN ('rejected','published');
ALTER TABLE product_revisions ADD CONSTRAINT revision_status CHECK(status IN ('draft','submitted','under_review','changes_requested','rejected','approved','published'));
CREATE FUNCTION templaara_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Revision history cannot be deleted'; END IF;
 IF NOT EXISTS(SELECT 1 FROM product_releases WHERE id=NEW.base_release_id AND product_id=NEW.product_id AND status='published') OR NOT EXISTS(SELECT 1 FROM product_releases WHERE id=NEW.proposed_release_id AND product_id=NEW.product_id) THEN RAISE EXCEPTION 'Revision releases must belong to its published product'; END IF;
 IF TG_OP='UPDATE' THEN
 IF (NEW.product_id,NEW.base_release_id,NEW.proposed_release_id,NEW.created_by) IS DISTINCT FROM (OLD.product_id,OLD.base_release_id,OLD.proposed_release_id,OLD.created_by) OR (OLD.status NOT IN ('draft','changes_requested') AND NEW.content IS DISTINCT FROM OLD.content) THEN RAISE EXCEPTION 'Frozen revision identity/content'; END IF;
 IF NEW.status<>OLD.status AND NOT ((OLD.status IN ('draft','changes_requested') AND NEW.status='submitted') OR (OLD.status='submitted' AND NEW.status='under_review') OR (OLD.status='under_review' AND NEW.status IN ('changes_requested','rejected','approved')) OR (OLD.status='approved' AND NEW.status='published')) THEN RAISE EXCEPTION 'Invalid revision transition'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER revision_guard BEFORE INSERT OR UPDATE OR DELETE ON product_revisions FOR EACH ROW EXECUTE FUNCTION templaara_revision_guard();
CREATE FUNCTION templaara_revision_media_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r product_revisions; BEGIN
 SELECT * INTO r FROM product_revisions WHERE id=COALESCE(NEW.product_revision_id,OLD.product_revision_id) FOR UPDATE;
 IF r.status NOT IN ('draft','changes_requested') THEN RAISE EXCEPTION 'Frozen revision media'; END IF;
 IF TG_OP='INSERT' AND NOT EXISTS(SELECT 1 FROM product_media WHERE id=NEW.product_media_id AND product_id=r.product_id) THEN RAISE EXCEPTION 'Revision media product mismatch'; END IF;
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE TRIGGER revision_media_guard BEFORE INSERT OR UPDATE OR DELETE ON product_revision_media FOR EACH ROW EXECUTE FUNCTION templaara_revision_media_guard();
CREATE FUNCTION templaara_revision_submission_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='UPDATE' AND NEW.product_revision_id IS DISTINCT FROM OLD.product_revision_id THEN RAISE EXCEPTION 'Submission revision is immutable'; END IF;
 IF NEW.product_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM product_revisions WHERE id=NEW.product_revision_id AND product_id=NEW.product_id AND proposed_release_id=(NEW.snapshot->'release'->>'id')::bigint) THEN RAISE EXCEPTION 'Submission revision mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER revision_submission_guard BEFORE INSERT OR UPDATE ON product_submissions FOR EACH ROW EXECUTE FUNCTION templaara_revision_submission_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Revision history requires a reviewed forward migration.');
    }
};
