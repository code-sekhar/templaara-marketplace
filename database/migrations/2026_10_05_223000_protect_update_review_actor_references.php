<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_update_review_actor_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.product_revision_id IS NULL THEN RETURN NEW; END IF;
 IF NEW.reviewed_by IS NOT NULL AND EXISTS(SELECT 1 FROM products WHERE id=NEW.product_id AND owner_id=NEW.reviewed_by) THEN RAISE EXCEPTION 'Product owners cannot review their update'; END IF;
 IF TG_OP='UPDATE' AND OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by,NEW.review_started_at) IS DISTINCT FROM (OLD.reviewed_by,OLD.review_started_at) THEN RAISE EXCEPTION 'Review claim is immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER update_review_actor_guard BEFORE INSERT OR UPDATE ON product_submissions FOR EACH ROW EXECUTE FUNCTION templaara_update_review_actor_guard();
CREATE FUNCTION templaara_update_event_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.product_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM product_submissions s JOIN product_revisions r ON r.id=s.product_revision_id WHERE s.id=NEW.product_submission_id AND s.product_id=NEW.product_id AND r.id=NEW.product_revision_id AND r.product_id=NEW.product_id AND (NEW.action NOT IN ('start_review','request_changes','reject','approve') OR s.reviewed_by=NEW.actor_id) AND (NEW.action<>'submit' OR s.submitted_by=NEW.actor_id)) THEN RAISE EXCEPTION 'Update audit actor/round mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER update_event_reference_guard BEFORE INSERT ON product_lifecycle_events FOR EACH ROW EXECUTE FUNCTION templaara_update_event_reference_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed forward migration required.');
    }
};
