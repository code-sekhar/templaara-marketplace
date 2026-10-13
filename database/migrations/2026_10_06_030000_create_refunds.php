<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('reference', 50)->unique();
            $t->uuid('request_key');
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $t->string('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->string('status', 20)->default('requested');
            $t->string('reason_code', 40);
            $t->text('customer_reason')->nullable();
            $t->unsignedInteger('window_days');
            $t->timestampTz('window_ends_at');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('requested_at');
            foreach (['reviewed', 'processing', 'completed', 'failed', 'cancelled'] as $s) {
                $t->foreignId($s.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestampTz($s.'_at')->nullable();
            }
            $t->string('external_reference', 255)->nullable();
            $t->text('failure_reason')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->text('admin_note')->nullable();
            $t->timestampsTz();
            $t->unique(['requested_by', 'request_key']);
            $t->index(['customer_user_id', 'requested_at']);
            $t->index(['status', 'currency', 'requested_at']);
        });
        Schema::create('refund_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_earning_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->decimal('commission_amount', 14, 2);
            $t->decimal('author_amount', 14, 2);
            $t->jsonb('review_facts');
            $t->timestampTz('created_at');
            $t->unique(['refund_id', 'order_item_id']);
        });
        Schema::create('purchase_refund_states', function (Blueprint $t) {
            $t->foreignId('order_item_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignId('active_refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $t->foreignId('completed_refund_item_id')->nullable()->unique()->constrained('refund_items')->restrictOnDelete();
        });
        Schema::create('refund_compensations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_item_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('author_earning_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('author_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('currency', 3);
            $t->decimal('gross_amount', 14, 2);
            $t->decimal('commission_amount', 14, 2);
            $t->decimal('author_amount', 14, 2);
            $t->string('earning_bucket', 20)->nullable();
            $t->decimal('paid_exposure', 14, 2)->default(0);
            $t->timestampTz('created_at');
        });
        Schema::create('refund_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('previous_status', 20)->nullable();
            $t->string('status', 20);
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at');
            $t->unique(['refund_id', 'status']);
        });
        Schema::table('author_earning_ledger_entries', function (Blueprint $t) {
            $t->foreignId('refund_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        // SQLite rebuilds the ledger when adding its FK, losing partial-index predicates.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS original_earning_ledger_unique');
            DB::statement('CREATE UNIQUE INDEX original_earning_ledger_unique ON author_earning_ledger_entries(author_earning_id,type) WHERE payout_allocation_id IS NULL');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE refunds ADD CONSTRAINT refund_values CHECK(amount>0 AND currency IN ('INR','USD','EUR','GBP') AND status IN ('requested','approved','processing','completed','failed','cancelled') AND reason_code IN ('duplicate_purchase','product_not_as_described','technical_issue','customer_request','fraud_or_risk','other') AND window_days BETWEEN 0 AND 3650 AND requested_at<=window_ends_at AND ((reviewed_at IS NULL)=(reviewed_by IS NULL)) AND ((processing_at IS NULL)=(processing_by IS NULL)) AND ((completed_at IS NULL)=(completed_by IS NULL)) AND ((failed_at IS NULL)=(failed_by IS NULL)) AND ((cancelled_at IS NULL)=(cancelled_by IS NULL)) AND ((status='completed')=(completed_at IS NOT NULL)) AND ((status='failed')=(failed_at IS NOT NULL)) AND ((status='cancelled')=(cancelled_at IS NOT NULL)) AND (status NOT IN ('approved','processing','completed','failed') OR reviewed_at IS NOT NULL) AND (status NOT IN ('processing','completed','failed') OR processing_at IS NOT NULL) AND (reviewed_at IS NULL OR reviewed_at>=requested_at) AND (processing_at IS NULL OR processing_at>=reviewed_at) AND (completed_at IS NULL OR completed_at>=processing_at) AND (failed_at IS NULL OR failed_at>=processing_at) AND (cancelled_at IS NULL OR cancelled_at>=requested_at) AND (status<>'requested' OR reviewed_at IS NULL) AND (processing_at IS NULL OR status IN ('processing','completed','failed')) AND (status<>'completed' OR (external_reference IS NOT NULL AND length(trim(external_reference))>=3)) AND (external_reference IS NULL OR status='completed') AND (status<>'failed' OR (failure_reason IS NOT NULL AND length(trim(failure_reason))>=5)) AND (status<>'cancelled' OR (cancellation_reason IS NOT NULL AND length(trim(cancellation_reason))>=5)));
ALTER TABLE refund_items ADD CONSTRAINT refund_item_money CHECK(amount>0 AND commission_amount>=0 AND author_amount>=0 AND commission_amount+author_amount<=amount);
ALTER TABLE purchase_refund_states ADD CONSTRAINT refund_state_exclusive CHECK(active_refund_id IS NULL OR completed_refund_item_id IS NULL);
ALTER TABLE refund_compensations ADD CONSTRAINT refund_compensation_money CHECK(gross_amount>0 AND commission_amount>=0 AND author_amount>=0 AND paid_exposure>=0 AND paid_exposure<=author_amount AND currency IN ('INR','USD','EUR','GBP') AND ((author_earning_id IS NULL AND author_user_id IS NULL AND earning_bucket IS NULL AND author_amount=0 AND commission_amount=0 AND paid_exposure=0) OR (author_earning_id IS NOT NULL AND author_user_id IS NOT NULL AND earning_bucket IN ('pending','available') AND gross_amount=commission_amount+author_amount)));
CREATE FUNCTION templaara_refund_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Refund history retained'; END IF;
 IF TG_OP='INSERT' THEN
 IF NEW.status<>'requested' OR NEW.reviewed_at IS NOT NULL OR NEW.processing_at IS NOT NULL THEN RAISE EXCEPTION 'Refund starts requested'; END IF;
 IF NOT EXISTS(SELECT 1 FROM orders o JOIN payments p ON p.id=o.settled_payment_id WHERE o.id=NEW.order_id AND o.user_id=NEW.customer_user_id AND o.status='completed' AND o.payment_status='paid' AND p.order_id=o.id AND p.status='paid' AND p.amount=o.grand_total AND p.currency=o.currency AND o.currency=NEW.currency AND NEW.window_ends_at=o.paid_at+NEW.window_days*interval '1 day') THEN RAISE EXCEPTION 'Refund requires owned verified paid order'; END IF;
 ELSE
 IF (to_jsonb(NEW)-ARRAY['status','reviewed_at','reviewed_by','processing_at','processing_by','completed_at','completed_by','failed_at','failed_by','cancelled_at','cancelled_by','failure_reason','cancellation_reason','admin_note','external_reference','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','reviewed_at','reviewed_by','processing_at','processing_by','completed_at','completed_by','failed_at','failed_by','cancelled_at','cancelled_by','failure_reason','cancellation_reason','admin_note','external_reference','updated_at']) THEN RAISE EXCEPTION 'Refund snapshot immutable'; END IF;
 IF NOT((OLD.status='requested' AND NEW.status IN ('approved','cancelled')) OR (OLD.status='approved' AND NEW.status IN ('processing','cancelled')) OR (OLD.status='processing' AND NEW.status IN ('completed','failed'))) THEN RAISE EXCEPTION 'Invalid refund transition'; END IF;
 IF (OLD.reviewed_at IS NOT NULL AND (OLD.reviewed_at,OLD.reviewed_by) IS DISTINCT FROM (NEW.reviewed_at,NEW.reviewed_by)) OR (OLD.processing_at IS NOT NULL AND (OLD.processing_at,OLD.processing_by) IS DISTINCT FROM (NEW.processing_at,NEW.processing_by)) THEN RAISE EXCEPTION 'Refund audit immutable'; END IF;
 END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_guard BEFORE INSERT OR UPDATE OR DELETE ON refunds FOR EACH ROW EXECUTE FUNCTION templaara_refund_guard();
CREATE FUNCTION templaara_refund_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Refund item immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM refunds r JOIN order_items i ON i.order_id=r.order_id LEFT JOIN author_earnings e ON e.order_item_id=i.id WHERE r.id=NEW.refund_id AND i.id=NEW.order_item_id AND r.status='requested' AND i.currency=r.currency AND i.line_total=NEW.amount AND ((i.seller_type='platform' AND NEW.author_earning_id IS NULL AND NEW.commission_amount=0 AND NEW.author_amount=0) OR (i.seller_type='author' AND e.id=NEW.author_earning_id AND i.commission_amount=NEW.commission_amount AND i.author_amount=NEW.author_amount))) THEN RAISE EXCEPTION 'Refund item must match immutable commercial snapshot'; END IF;
 RETURN NEW;END;$$;
CREATE TRIGGER refund_item_guard BEFORE INSERT OR UPDATE OR DELETE ON refund_items FOR EACH ROW EXECUTE FUNCTION templaara_refund_item_guard();
CREATE FUNCTION templaara_refund_state_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR (TG_OP='UPDATE' AND (NEW.order_item_id<>OLD.order_item_id OR OLD.completed_refund_item_id IS NOT NULL)) THEN RAISE EXCEPTION 'Refund purchase state retained'; END IF;
 IF TG_OP='UPDATE' AND OLD.active_refund_id IS NOT NULL AND NEW.active_refund_id IS DISTINCT FROM OLD.active_refund_id AND EXISTS(SELECT 1 FROM refunds WHERE id=OLD.active_refund_id AND status IN ('requested','approved','processing')) THEN RAISE EXCEPTION 'Unresolved refund claim cannot be replaced'; END IF;
 PERFORM 1 FROM order_items WHERE id=NEW.order_item_id FOR UPDATE;
 IF NEW.active_refund_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM refund_items i JOIN refunds r ON r.id=i.refund_id WHERE i.order_item_id=NEW.order_item_id AND r.id=NEW.active_refund_id AND r.status IN ('requested','approved','processing')) THEN RAISE EXCEPTION 'Invalid active refund claim'; END IF;
 IF NEW.completed_refund_item_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM refund_items i JOIN refunds r ON r.id=i.refund_id WHERE i.order_item_id=NEW.order_item_id AND i.id=NEW.completed_refund_item_id AND r.status='completed') THEN RAISE EXCEPTION 'Invalid completed refund claim'; END IF;
 RETURN NEW;END;$$;
CREATE TRIGGER refund_state_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_refund_states FOR EACH ROW EXECUTE FUNCTION templaara_refund_state_guard();
CREATE FUNCTION templaara_refund_compensation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Refund compensation immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM refund_items i JOIN refunds r ON r.id=i.refund_id LEFT JOIN author_earnings e ON e.id=i.author_earning_id WHERE i.id=NEW.refund_item_id AND i.order_item_id=NEW.order_item_id AND r.status='completed' AND r.currency=NEW.currency AND i.amount=NEW.gross_amount AND i.commission_amount=NEW.commission_amount AND i.author_amount=NEW.author_amount AND i.author_earning_id IS NOT DISTINCT FROM NEW.author_earning_id AND (e.id IS NULL OR (e.author_user_id=NEW.author_user_id AND e.status=NEW.earning_bucket))) THEN RAISE EXCEPTION 'Refund compensation snapshot mismatch'; END IF;
 IF NEW.author_user_id IS NOT NULL THEN
 PERFORM 1 FROM users WHERE id=NEW.author_user_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM payout_requests WHERE author_user_id=NEW.author_user_id AND currency=NEW.currency AND status IN ('requested','approved','processing')) THEN RAISE EXCEPTION 'Resolve unpaid payouts before refund'; END IF;
 IF NEW.paid_exposure<>(SELECT coalesce(sum(amount),0) FROM author_earning_ledger_entries WHERE author_earning_id=NEW.author_earning_id AND type='payout_paid') THEN RAISE EXCEPTION 'Refund paid exposure mismatch'; END IF;
 END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_compensation_guard BEFORE INSERT OR UPDATE OR DELETE ON refund_compensations FOR EACH ROW EXECUTE FUNCTION templaara_refund_compensation_guard();
CREATE FUNCTION templaara_refund_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE r refunds%ROWTYPE;prior text;BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Refund audit append-only'; END IF;
 SELECT * INTO r FROM refunds WHERE id=NEW.refund_id FOR UPDATE;SELECT status INTO prior FROM refund_events WHERE refund_id=r.id ORDER BY id DESC LIMIT 1;
 IF (prior IS NULL AND NEW.status<>'requested') OR (prior IS NOT NULL AND NOT((prior='requested' AND NEW.status IN ('approved','cancelled')) OR (prior='approved' AND NEW.status IN ('processing','cancelled')) OR (prior='processing' AND NEW.status IN ('completed','failed')))) THEN RAISE EXCEPTION 'Refund audit chain incomplete'; END IF;
 IF NEW.status<>r.status OR NEW.previous_status IS DISTINCT FROM prior OR NEW.actor_id IS DISTINCT FROM (CASE NEW.status WHEN 'requested' THEN r.requested_by WHEN 'approved' THEN r.reviewed_by WHEN 'processing' THEN r.processing_by WHEN 'completed' THEN r.completed_by WHEN 'failed' THEN r.failed_by WHEN 'cancelled' THEN r.cancelled_by END) OR NEW.occurred_at IS DISTINCT FROM (CASE NEW.status WHEN 'requested' THEN r.requested_at WHEN 'approved' THEN r.reviewed_at WHEN 'processing' THEN r.processing_at WHEN 'completed' THEN r.completed_at WHEN 'failed' THEN r.failed_at WHEN 'cancelled' THEN r.cancelled_at END) THEN RAISE EXCEPTION 'Refund audit actor mismatch'; END IF;
 RETURN NEW;END;$$;
CREATE TRIGGER refund_event_guard BEFORE INSERT OR UPDATE OR DELETE ON refund_events FOR EACH ROW EXECUTE FUNCTION templaara_refund_event_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Refund financial history requires reviewed forward migrations.');
    }
};
