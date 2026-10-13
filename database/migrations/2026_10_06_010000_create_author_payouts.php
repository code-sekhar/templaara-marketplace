<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('author_payout_methods', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('author_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->string('currency', 3);
            $t->string('country_code', 2);
            $t->string('display_label', 100);
            $t->string('bank_name', 150);
            $t->string('masked_account', 12);
            $t->text('details_encrypted');
            $t->string('details_fingerprint', 64);
            $t->string('status', 20)->default('active');
            $t->boolean('is_default')->default(false);
            $t->timestampTz('disabled_at')->nullable();
            $t->timestampsTz();
            $t->index(['author_user_id', 'currency', 'status']);
            $t->unique(['author_user_id', 'details_fingerprint']);
        });
        DB::statement("CREATE UNIQUE INDEX payout_method_default ON author_payout_methods(author_user_id,currency) WHERE is_default AND status='active'");
        Schema::create('payout_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('payout_number', 50)->unique();
            $t->uuid('request_key');
            $t->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('author_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('payout_method_id')->constrained('author_payout_methods')->restrictOnDelete();
            $t->string('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->string('status', 20)->default('requested');
            $t->string('method_type', 30);
            $t->text('method_snapshot_encrypted');
            $t->jsonb('method_display_snapshot');
            $t->timestampTz('requested_at');
            foreach (['reviewed', 'processing', 'paid', 'failed', 'cancelled'] as $state) {
                $t->timestampTz($state.'_at')->nullable();
                $t->foreignId($state.'_by')->nullable()->constrained('users')->restrictOnDelete();
            }$t->text('failure_reason')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->text('admin_note')->nullable();
            $t->string('external_reference', 255)->nullable();
            $t->timestampsTz();
            $t->unique(['author_user_id', 'request_key']);
            $t->index(['author_user_id', 'currency', 'requested_at']);
            $t->index(['status', 'currency', 'requested_at']);
        });
        Schema::create('payout_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_earning_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->timestampTz('created_at');
            $t->unique(['payout_request_id', 'author_earning_id']);
            $t->index(['author_earning_id', 'payout_request_id']);
        });
        Schema::create('payout_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('previous_status', 20)->nullable();
            $t->string('status', 20);
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at');
            $t->unique(['payout_request_id', 'status']);
        });
        Schema::table('author_earning_ledger_entries', function (Blueprint $t) {
            $t->foreignId('payout_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $t->dropUnique(['author_earning_id', 'type']);
            $t->unique(['payout_allocation_id', 'type']);
        });
        DB::statement('CREATE UNIQUE INDEX original_earning_ledger_unique ON author_earning_ledger_entries(author_earning_id,type) WHERE payout_allocation_id IS NULL');
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE author_payout_methods ADD CONSTRAINT payout_method_values CHECK(type='bank_transfer' AND currency IN ('INR','USD','EUR','GBP') AND country_code ~ '^[A-Z]{2}$' AND status IN ('active','disabled') AND ((status='disabled')=(disabled_at IS NOT NULL)) AND NOT(is_default AND status='disabled') AND length(details_encrypted)>0 AND details_fingerprint ~ '^[a-f0-9]{64}$' AND masked_account ~ '^•••• [A-Z0-9]{4}$');
ALTER TABLE payout_requests ADD CONSTRAINT payout_request_values CHECK(amount>0 AND currency IN ('INR','USD','EUR','GBP') AND method_type='bank_transfer' AND length(method_snapshot_encrypted)>0 AND jsonb_typeof(method_display_snapshot)='object' AND status IN ('requested','approved','processing','paid','failed','cancelled') AND ((reviewed_at IS NULL)=(reviewed_by IS NULL)) AND ((processing_at IS NULL)=(processing_by IS NULL)) AND ((paid_at IS NULL)=(paid_by IS NULL)) AND ((failed_at IS NULL)=(failed_by IS NULL)) AND ((cancelled_at IS NULL)=(cancelled_by IS NULL)) AND ((status='paid')=(paid_at IS NOT NULL)) AND ((status='failed')=(failed_at IS NOT NULL)) AND ((status='cancelled')=(cancelled_at IS NOT NULL)) AND (status NOT IN ('approved','processing','paid','failed') OR reviewed_at IS NOT NULL) AND (status NOT IN ('processing','paid','failed') OR processing_at IS NOT NULL) AND (status<>'paid' OR (external_reference IS NOT NULL AND length(trim(external_reference))>0)) AND (status<>'failed' OR (failure_reason IS NOT NULL AND length(trim(failure_reason))>=5)));
ALTER TABLE payout_requests ADD CONSTRAINT payout_timeline CHECK(
 (reviewed_at IS NULL OR reviewed_at>=requested_at) AND
 (processing_at IS NULL OR processing_at>=reviewed_at) AND
 (paid_at IS NULL OR paid_at>=processing_at) AND
 (failed_at IS NULL OR failed_at>=processing_at) AND
 (cancelled_at IS NULL OR (cancelled_at>=requested_at AND (reviewed_at IS NULL OR cancelled_at>=reviewed_at))) AND
 (status<>'requested' OR (reviewed_at IS NULL AND processing_at IS NULL)) AND
 (processing_at IS NULL OR status IN ('processing','paid','failed')) AND
 (external_reference IS NULL OR status='paid') AND
 (failure_reason IS NULL OR status='failed') AND
 (cancellation_reason IS NULL OR status='cancelled') AND
 (status<>'cancelled' OR (cancellation_reason IS NOT NULL AND length(trim(cancellation_reason))>=5))
);
ALTER TABLE payout_allocations ADD CONSTRAINT payout_allocation_positive CHECK(amount>0);
ALTER TABLE author_earning_ledger_entries DROP CONSTRAINT ledger_values;
ALTER TABLE author_earning_ledger_entries ADD CONSTRAINT ledger_values CHECK(amount>=0 AND currency IN ('INR','USD','EUR','GBP') AND ((type IN ('earning_pending','earning_available') AND payout_allocation_id IS NULL) OR (type IN ('payout_reserved','payout_released','payout_paid') AND payout_allocation_id IS NOT NULL AND amount>0)));
CREATE FUNCTION templaara_payout_method_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Payout methods are retained'; END IF;
 IF TG_OP='UPDATE' AND (NEW.author_user_id,NEW.author_profile_id,NEW.public_uuid,NEW.type,NEW.currency,NEW.created_at) IS DISTINCT FROM (OLD.author_user_id,OLD.author_profile_id,OLD.public_uuid,OLD.type,OLD.currency,OLD.created_at) THEN RAISE EXCEPTION 'Payout method identity immutable'; END IF;
 IF NEW.author_profile_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM author_profiles WHERE id=NEW.author_profile_id AND user_id=NEW.author_user_id) THEN RAISE EXCEPTION 'Payout method author profile mismatch'; END IF;
 RETURN NEW;END; $$;
CREATE TRIGGER payout_method_guard BEFORE INSERT OR UPDATE OR DELETE ON author_payout_methods FOR EACH ROW EXECUTE FUNCTION templaara_payout_method_guard();
CREATE FUNCTION templaara_payout_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Payout history retained'; END IF;
 IF TG_OP='INSERT' THEN
 IF NEW.status<>'requested' OR NEW.reviewed_at IS NOT NULL OR NEW.processing_at IS NOT NULL OR NEW.external_reference IS NOT NULL THEN RAISE EXCEPTION 'Payout starts requested'; END IF;
 IF NOT EXISTS(SELECT 1 FROM author_payout_methods WHERE id=NEW.payout_method_id AND author_user_id=NEW.author_user_id AND author_profile_id IS NOT DISTINCT FROM NEW.author_profile_id AND currency=NEW.currency AND status='active' AND type=NEW.method_type AND (NEW.method_display_snapshot->>'label')=display_label AND (NEW.method_display_snapshot->>'masked_account')=masked_account) THEN RAISE EXCEPTION 'Payout requires own active matching destination'; END IF;
 ELSE
 IF (to_jsonb(NEW)-ARRAY['status','reviewed_at','reviewed_by','processing_at','processing_by','paid_at','paid_by','failed_at','failed_by','cancelled_at','cancelled_by','failure_reason','cancellation_reason','admin_note','external_reference','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','reviewed_at','reviewed_by','processing_at','processing_by','paid_at','paid_by','failed_at','failed_by','cancelled_at','cancelled_by','failure_reason','cancellation_reason','admin_note','external_reference','updated_at']) THEN RAISE EXCEPTION 'Payout financial snapshot immutable'; END IF;
 IF NOT((OLD.status='requested' AND NEW.status IN ('approved','cancelled')) OR (OLD.status='approved' AND NEW.status IN ('processing','cancelled')) OR (OLD.status='processing' AND NEW.status IN ('paid','failed'))) THEN RAISE EXCEPTION 'Invalid payout transition'; END IF;
 IF (OLD.reviewed_at IS NOT NULL AND (NEW.reviewed_at,NEW.reviewed_by) IS DISTINCT FROM (OLD.reviewed_at,OLD.reviewed_by)) OR (OLD.processing_at IS NOT NULL AND (NEW.processing_at,NEW.processing_by) IS DISTINCT FROM (OLD.processing_at,OLD.processing_by)) THEN RAISE EXCEPTION 'Payout review history immutable'; END IF;
 IF NEW.status='approved' AND NEW.reviewed_at IS NULL OR NEW.status='processing' AND NEW.processing_at IS NULL THEN RAISE EXCEPTION 'Missing payout actor'; END IF;
 END IF;RETURN NEW;END; $$;
CREATE TRIGGER payout_guard BEFORE INSERT OR UPDATE OR DELETE ON payout_requests FOR EACH ROW EXECUTE FUNCTION templaara_payout_guard();
CREATE FUNCTION templaara_payout_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE e author_earnings%ROWTYPE;p payout_requests%ROWTYPE; used numeric; BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Payout allocation immutable'; END IF;
 SELECT * INTO p FROM payout_requests WHERE id=NEW.payout_request_id;
 PERFORM 1 FROM users WHERE id=p.author_user_id FOR UPDATE;
 SELECT * INTO e FROM author_earnings WHERE id=NEW.author_earning_id FOR UPDATE;
 IF p.status<>'requested' OR e.status<>'available' OR p.author_user_id<>e.author_user_id OR p.currency<>e.currency THEN RAISE EXCEPTION 'Allocation requires own available matching currency earning'; END IF;
 SELECT coalesce(sum(a.amount),0) INTO used FROM payout_allocations a JOIN payout_requests r ON r.id=a.payout_request_id WHERE a.author_earning_id=e.id AND r.status NOT IN ('failed','cancelled');
 IF NEW.amount+used>e.author_amount THEN RAISE EXCEPTION 'Allocation exceeds available earning'; END IF;
 RETURN NEW;END; $$;
CREATE TRIGGER payout_allocation_guard BEFORE INSERT OR UPDATE OR DELETE ON payout_allocations FOR EACH ROW EXECUTE FUNCTION templaara_payout_allocation_guard();
CREATE OR REPLACE FUNCTION templaara_earning_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Financial ledger append-only'; END IF;
 IF NEW.type IN ('earning_pending','earning_available') THEN
 IF NOT EXISTS(SELECT 1 FROM author_earnings e WHERE e.id=NEW.author_earning_id AND e.author_user_id=NEW.author_user_id AND e.currency=NEW.currency AND e.author_amount=NEW.amount AND ((NEW.type='earning_pending' AND NEW.occurred_at=e.created_at) OR (NEW.type='earning_available' AND e.status='available' AND NEW.occurred_at=e.released_at))) THEN RAISE EXCEPTION 'Earning ledger mismatch'; END IF;
 ELSE
 IF NOT EXISTS(SELECT 1 FROM payout_allocations a JOIN payout_requests p ON p.id=a.payout_request_id WHERE a.id=NEW.payout_allocation_id AND a.author_earning_id=NEW.author_earning_id AND a.amount=NEW.amount AND p.author_user_id=NEW.author_user_id AND p.currency=NEW.currency AND ((NEW.type='payout_reserved' AND p.status='requested' AND NEW.occurred_at=p.requested_at) OR (NEW.type='payout_paid' AND p.status='paid' AND NEW.occurred_at=p.paid_at) OR (NEW.type='payout_released' AND p.status IN ('failed','cancelled') AND NEW.occurred_at=coalesce(p.failed_at,p.cancelled_at)))) THEN RAISE EXCEPTION 'Payout ledger mismatch'; END IF;
 END IF;RETURN NEW;END; $$;
CREATE FUNCTION templaara_payout_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE p payout_requests%ROWTYPE; prior text;BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Payout audit append-only'; END IF;
 SELECT * INTO p FROM payout_requests WHERE id=NEW.payout_request_id FOR UPDATE;
 SELECT status INTO prior FROM payout_events WHERE payout_request_id=p.id ORDER BY id DESC LIMIT 1;
 IF NEW.status<>p.status OR NEW.previous_status IS DISTINCT FROM prior OR NEW.actor_id IS DISTINCT FROM (CASE NEW.status WHEN 'requested' THEN p.author_user_id WHEN 'approved' THEN p.reviewed_by WHEN 'processing' THEN p.processing_by WHEN 'paid' THEN p.paid_by WHEN 'failed' THEN p.failed_by WHEN 'cancelled' THEN p.cancelled_by END) OR NEW.occurred_at IS DISTINCT FROM (CASE NEW.status WHEN 'requested' THEN p.requested_at WHEN 'approved' THEN p.reviewed_at WHEN 'processing' THEN p.processing_at WHEN 'paid' THEN p.paid_at WHEN 'failed' THEN p.failed_at WHEN 'cancelled' THEN p.cancelled_at END) THEN RAISE EXCEPTION 'Payout audit actor/state mismatch'; END IF;
 RETURN NEW;END; $$;
CREATE TRIGGER payout_event_guard BEFORE INSERT OR UPDATE OR DELETE ON payout_events FOR EACH ROW EXECUTE FUNCTION templaara_payout_event_guard();
CREATE FUNCTION templaara_payout_audit_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE p payout_requests%ROWTYPE;BEGIN
 IF TG_TABLE_NAME='payout_requests' THEN SELECT * INTO p FROM payout_requests WHERE id=NEW.id; ELSE SELECT * INTO p FROM payout_requests WHERE id=NEW.payout_request_id; END IF;
 IF coalesce((SELECT sum(amount) FROM payout_allocations WHERE payout_request_id=p.id),0)<>p.amount THEN RAISE EXCEPTION 'Payout allocations must total request'; END IF;
 IF NOT EXISTS(SELECT 1 FROM payout_events WHERE payout_request_id=p.id AND status=p.status) THEN RAISE EXCEPTION 'Payout requires lifecycle audit'; END IF;
 IF EXISTS(SELECT 1 FROM payout_allocations a WHERE a.payout_request_id=p.id AND (NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE payout_allocation_id=a.id AND type='payout_reserved') OR (p.status='paid' AND NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE payout_allocation_id=a.id AND type='payout_paid')) OR (p.status IN ('failed','cancelled') AND NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE payout_allocation_id=a.id AND type='payout_released')))) THEN RAISE EXCEPTION 'Payout requires exact ledger movements'; END IF;
 RETURN NEW;END; $$;
CREATE CONSTRAINT TRIGGER payout_audit_guard AFTER INSERT OR UPDATE ON payout_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_payout_audit_guard();
CREATE CONSTRAINT TRIGGER allocation_payout_audit_guard AFTER INSERT ON payout_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_payout_audit_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Financial history requires reviewed forward migrations.');
    }
};
