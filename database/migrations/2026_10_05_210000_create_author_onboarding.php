<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('author_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('display_name', 150)->nullable();
            $t->string('company_name', 200)->nullable();
            $t->string('slug', 200)->unique();
            $t->string('author_type', 20)->nullable();
            $t->char('country_code', 2)->nullable();
            $t->text('bio')->nullable();
            foreach (['website_url', 'portfolio_url', 'github_url', 'linkedin_url'] as $f) {
                $t->string($f, 2048)->nullable();
            }
            $t->string('support_email')->nullable();
            $t->string('support_phone', 40)->nullable();
            $t->string('status', 30)->default('draft');
            $t->boolean('is_legacy')->default(false);
            $t->timestamps();
            $t->index(['status', 'id']);
            $t->index(['author_type', 'country_code']);
        });
        Schema::create('author_applications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_profile_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('round_number');
            $t->string('status', 30);
            $t->timestampTz('submitted_at');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('review_started_at')->nullable();
            $t->timestampTz('decision_at')->nullable();
            $t->text('decision_summary')->nullable();
            $t->unsignedSmallInteger('snapshot_version')->default(1);
            $t->jsonb('snapshot');
            $t->timestamps();
            $t->unique(['author_profile_id', 'round_number']);
            $t->index(['status', 'submitted_at', 'id']);
            $t->index(['user_id', 'id']);
            $t->index('reviewed_by');
        });
        Schema::create('author_application_feedback', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('author_application_id')->constrained()->restrictOnDelete();
            $t->string('section', 30);
            $t->text('message');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['author_application_id', 'id']);
            $t->index('created_by');
        });
        Schema::create('author_application_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('author_application_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 30);
            $t->string('from_status', 30);
            $t->string('to_status', 30);
            $t->timestampTz('occurred_at');
            $t->timestamps();
            $t->index(['author_application_id', 'id']);
            $t->index('actor_id');
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE author_profiles ADD CONSTRAINT author_profile_state CHECK (status IN ('draft','submitted','under_review','changes_requested','approved','rejected') AND (NOT is_legacy OR status='approved') AND (author_type IS NULL OR author_type IN ('individual','company')) AND (country_code IS NULL OR country_code ~ '^[A-Z]{2}$') AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("CREATE UNIQUE INDEX author_one_open_round ON author_applications (author_profile_id) WHERE status IN ('submitted','under_review')");
        DB::statement("ALTER TABLE author_applications ADD CONSTRAINT author_application_state CHECK (round_number>0 AND snapshot_version=1 AND jsonb_typeof(snapshot)='object' AND status IN ('submitted','under_review','changes_requested','approved','rejected') AND ((reviewed_by IS NULL)=(review_started_at IS NULL)) AND ((status='submitted')=(reviewed_by IS NULL)) AND ((status IN ('changes_requested','approved','rejected'))=(decision_at IS NOT NULL)) AND (status<>'rejected' OR length(trim(decision_summary))>=10))");
        DB::statement("ALTER TABLE author_application_feedback ADD CONSTRAINT author_feedback_values CHECK (section IN ('general','identity','profile','company','portfolio','contact') AND length(trim(message)) BETWEEN 10 AND 5000)");
        DB::statement("ALTER TABLE author_application_events ADD CONSTRAINT author_event_values CHECK ((action='submitted' AND from_status='draft' AND to_status='submitted') OR (action='resubmitted' AND from_status='changes_requested' AND to_status='submitted') OR (action='review_started' AND from_status='submitted' AND to_status='under_review') OR (action IN ('changes_requested','approved','rejected') AND from_status='under_review' AND to_status=action))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_author_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p author_profiles%ROWTYPE; a author_applications%ROWTYPE;
BEGIN
IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Author moderation history is retained'; END IF;
IF TG_TABLE_NAME='author_profiles' THEN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' AND NOT (NEW.is_legacy AND NEW.status='approved' AND EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id=m.role_id WHERE m.model_id=NEW.user_id AND m.model_type='App\Models\User' AND r.name='author' AND r.guard_name='web')) THEN RAISE EXCEPTION 'New author profile must be draft or existing legacy author'; END IF;
 ELSE
  IF NEW.user_id<>OLD.user_id OR NEW.is_legacy<>OLD.is_legacy THEN RAISE EXCEPTION 'Author ownership immutable'; END IF;
  IF OLD.status NOT IN ('draft','changes_requested') AND (to_jsonb(NEW)-ARRAY['status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','updated_at']) THEN RAISE EXCEPTION 'Submitted profile frozen'; END IF;
  IF NEW.status<>OLD.status THEN
   IF NOT ((OLD.status IN ('draft','changes_requested') AND NEW.status='submitted') OR (OLD.status='submitted' AND NEW.status='under_review') OR (OLD.status='under_review' AND NEW.status IN ('changes_requested','approved','rejected'))) THEN RAISE EXCEPTION 'Invalid profile transition'; END IF;
   SELECT * INTO a FROM author_applications WHERE author_profile_id=NEW.id ORDER BY round_number DESC LIMIT 1;
   IF a.id IS NULL OR a.status<>NEW.status THEN RAISE EXCEPTION 'Profile must match latest application'; END IF;
  END IF;
 END IF;
ELSIF TG_TABLE_NAME='author_applications' THEN
 SELECT * INTO p FROM author_profiles WHERE id=NEW.author_profile_id;
 IF NEW.user_id<>p.user_id OR NEW.reviewed_by=NEW.user_id THEN RAISE EXCEPTION 'Application ownership or self review violation'; END IF;
 IF TG_OP='INSERT' THEN
  IF p.status NOT IN ('draft','changes_requested') OR NEW.status<>'submitted' OR NEW.round_number<>COALESCE((SELECT max(round_number)+1 FROM author_applications WHERE author_profile_id=p.id),1) THEN RAISE EXCEPTION 'Invalid application round'; END IF;
  IF NEW.snapshot IS DISTINCT FROM (to_jsonb(p)-ARRAY['id','user_id','status','is_legacy','created_at','updated_at']) THEN RAISE EXCEPTION 'Application snapshot must match submitted profile'; END IF;
 ELSE
  IF OLD.decision_at IS NOT NULL OR (to_jsonb(NEW)-ARRAY['status','reviewed_by','review_started_at','decision_at','decision_summary','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','reviewed_by','review_started_at','decision_at','decision_summary','updated_at']) THEN RAISE EXCEPTION 'Application snapshot and closed rounds immutable'; END IF;
  IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by IS DISTINCT FROM OLD.reviewed_by OR NEW.review_started_at IS DISTINCT FROM OLD.review_started_at) THEN RAISE EXCEPTION 'Review claim immutable'; END IF;
  IF NOT ((OLD.status='submitted' AND NEW.status='under_review') OR (OLD.status='under_review' AND NEW.status IN ('changes_requested','approved','rejected'))) THEN RAISE EXCEPTION 'Invalid application transition'; END IF;
  IF NEW.status='changes_requested' THEN NULL; END IF;
 END IF;
ELSE
 IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Author audit immutable'; END IF;
 SELECT * INTO a FROM author_applications WHERE id=NEW.author_application_id;
 IF TG_TABLE_NAME='author_application_feedback' THEN
  IF a.status<>'changes_requested' OR NEW.created_by<>a.reviewed_by THEN RAISE EXCEPTION 'Feedback requires matching review decision actor'; END IF;
 ELSE
  SELECT * INTO p FROM author_profiles WHERE id=a.author_profile_id;
  IF NEW.to_status<>a.status OR p.status<>a.status OR (NEW.action IN ('submitted','resubmitted') AND NEW.actor_id<>a.user_id) OR (NEW.action NOT IN ('submitted','resubmitted') AND NEW.actor_id IS DISTINCT FROM a.reviewed_by) THEN RAISE EXCEPTION 'Audit actor or state mismatch'; END IF;
 END IF;
END IF;
RETURN NEW;
END;
$$;
CREATE TRIGGER author_profile_integrity BEFORE INSERT OR UPDATE OR DELETE ON author_profiles FOR EACH ROW EXECUTE FUNCTION templaara_author_guard();
CREATE TRIGGER author_application_integrity BEFORE INSERT OR UPDATE OR DELETE ON author_applications FOR EACH ROW EXECUTE FUNCTION templaara_author_guard();
CREATE TRIGGER author_feedback_integrity BEFORE INSERT OR UPDATE OR DELETE ON author_application_feedback FOR EACH ROW EXECUTE FUNCTION templaara_author_guard();
CREATE TRIGGER author_event_integrity BEFORE INSERT OR UPDATE OR DELETE ON author_application_events FOR EACH ROW EXECUTE FUNCTION templaara_author_guard();
CREATE FUNCTION templaara_author_feedback_required() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.status='changes_requested' AND NOT EXISTS (SELECT 1 FROM author_application_feedback WHERE author_application_id=NEW.id) THEN RAISE EXCEPTION 'Changes requested requires actionable feedback'; END IF;
RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER author_feedback_required AFTER INSERT OR UPDATE ON author_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_author_feedback_required();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Author moderation history requires forward migrations.');
    }
};
