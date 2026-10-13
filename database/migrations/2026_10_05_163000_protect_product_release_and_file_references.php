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
CREATE FUNCTION templaara_product_release_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.product_id <> OLD.product_id OR NEW.created_by <> OLD.created_by
       OR (OLD.status <> 'draft' AND (NEW.version,NEW.changelog,NEW.release_notes) IS DISTINCT FROM (OLD.version,OLD.changelog,OLD.release_notes))
       OR (OLD.status = 'published' AND (NEW.status,NEW.released_at) IS DISTINCT FROM (OLD.status,OLD.released_at)) THEN
        RAISE EXCEPTION 'Frozen release content and identity cannot be overwritten';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER product_release_immutable_content BEFORE UPDATE ON product_releases FOR EACH ROW EXECUTE FUNCTION templaara_product_release_guard();
CREATE FUNCTION templaara_submission_file_product_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE submission_product bigint; file_product bigint;
BEGIN
    SELECT product_id INTO submission_product FROM product_submissions WHERE id = NEW.product_submission_id;
    IF TG_TABLE_NAME = 'product_submission_media' THEN
        SELECT product_id INTO file_product FROM product_media WHERE id = NEW.product_media_id;
    ELSE
        SELECT r.product_id INTO file_product FROM product_artifacts a JOIN product_releases r ON r.id = a.product_release_id WHERE a.id = NEW.product_artifact_id;
    END IF;
    IF submission_product IS DISTINCT FROM file_product THEN
        RAISE EXCEPTION 'Submission files must belong to its product';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER submission_media_product_guard BEFORE INSERT ON product_submission_media FOR EACH ROW EXECUTE FUNCTION templaara_submission_file_product_guard();
CREATE TRIGGER submission_artifact_product_guard BEFORE INSERT ON product_submission_artifacts FOR EACH ROW EXECUTE FUNCTION templaara_submission_file_product_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP TRIGGER submission_artifact_product_guard ON product_submission_artifacts');
        DB::statement('DROP TRIGGER submission_media_product_guard ON product_submission_media');
        DB::statement('DROP FUNCTION templaara_submission_file_product_guard()');
        DB::statement('DROP TRIGGER product_release_immutable_content ON product_releases');
        DB::statement('DROP FUNCTION templaara_product_release_guard()');
    }
};
