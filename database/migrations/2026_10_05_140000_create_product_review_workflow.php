<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT products_draft_status');
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_status CHECK (status IN ('draft','submitted','under_review','changes_requested','rejected','approved','published'))");
        }
        Schema::create('product_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('round_number');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('review_started_at')->nullable();
            $table->string('decision', 30)->nullable();
            $table->timestampTz('decision_at')->nullable();
            $table->text('decision_summary')->nullable();
            $table->smallInteger('snapshot_version')->default(1);
            $table->jsonb('snapshot');
            $table->timestampsTz();
            $table->unique(['product_id', 'round_number']);
            $table->index(['submitted_at', 'id']);
            $table->index(['reviewed_by', 'decision']);
        });
        Schema::create('product_review_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_submission_id')->constrained()->restrictOnDelete();
            $table->string('section', 30)->default('general');
            $table->text('message');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('product_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['product_id', 'occurred_at', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_submissions ADD CONSTRAINT submission_round_positive CHECK (round_number > 0)');
            DB::statement("ALTER TABLE product_submissions ADD CONSTRAINT submission_decision CHECK (decision IS NULL OR decision IN ('changes_requested','rejected','approved'))");
            DB::statement('ALTER TABLE product_submissions ADD CONSTRAINT submission_decision_time CHECK ((decision IS NULL) = (decision_at IS NULL))');
            DB::statement('CREATE UNIQUE INDEX product_submissions_one_open ON product_submissions (product_id) WHERE decision IS NULL');
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_review_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_TABLE_NAME <> 'product_submissions' THEN
        RAISE EXCEPTION 'Moderation history is immutable';
    END IF;
    IF OLD.decision_at IS NOT NULL OR NEW.product_id <> OLD.product_id OR NEW.round_number <> OLD.round_number
       OR NEW.submitted_by <> OLD.submitted_by OR NEW.submitted_at <> OLD.submitted_at
       OR NEW.snapshot IS DISTINCT FROM OLD.snapshot OR NEW.snapshot_version <> OLD.snapshot_version THEN
        RAISE EXCEPTION 'Submission identity, content and closed rounds are immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER product_submissions_history_guard BEFORE UPDATE OR DELETE ON product_submissions FOR EACH ROW EXECUTE FUNCTION templaara_review_history_guard();
CREATE TRIGGER product_feedback_history_guard BEFORE UPDATE OR DELETE ON product_review_feedback FOR EACH ROW EXECUTE FUNCTION templaara_review_history_guard();
CREATE TRIGGER product_events_history_guard BEFORE UPDATE OR DELETE ON product_lifecycle_events FOR EACH ROW EXECUTE FUNCTION templaara_review_history_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && (DB::table('products')->where('status', '<>', 'draft')->exists() || DB::table('product_submissions')->exists())) {
            throw new RuntimeException('Cannot remove workflow while non-draft products exist.');
        }
        Schema::dropIfExists('product_lifecycle_events');
        Schema::dropIfExists('product_review_feedback');
        Schema::dropIfExists('product_submissions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION templaara_review_history_guard()');
            DB::statement('ALTER TABLE products DROP CONSTRAINT products_status');
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_draft_status CHECK (status = 'draft')");
        }
    }
};
