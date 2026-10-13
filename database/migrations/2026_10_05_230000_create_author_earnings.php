<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_policies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('percentage', 5, 2);
            $t->unsignedInteger('hold_days');
            $t->boolean('is_default')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestampTz('effective_from');
            $t->timestampTz('effective_until')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['is_active', 'is_default', 'effective_from', 'effective_until']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            // NULL means legacy commercial facts are unknown, never inferred from current ownership/policy.
            $t->string('seller_type', 20)->nullable();
            $t->foreignId('author_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('author_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('commission_policy_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('commission_rate', 5, 2)->nullable();
            $t->decimal('commissionable_amount', 14, 2)->nullable();
            $t->decimal('commission_amount', 14, 2)->nullable();
            $t->decimal('author_amount', 14, 2)->nullable();
            $t->unsignedInteger('earning_hold_days')->nullable();
            $t->index(['seller_type', 'author_user_id']);
        });
        Schema::create('author_earnings', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('author_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_release_id')->constrained()->restrictOnDelete();
            $t->foreignId('commission_policy_id')->constrained()->restrictOnDelete();
            $t->string('currency', 3);
            $t->decimal('gross_amount', 14, 2);
            $t->decimal('commission_rate', 5, 2);
            $t->decimal('commission_amount', 14, 2);
            $t->decimal('author_amount', 14, 2);
            $t->unsignedInteger('hold_days');
            $t->string('status', 20)->default('pending');
            $t->timestampTz('available_at');
            $t->timestampTz('released_at')->nullable();
            $t->timestampsTz();
            $t->index(['author_user_id', 'status', 'currency', 'created_at']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('author_earning_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('author_earning_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $t->string('currency', 3);
            $t->string('type', 30);
            $t->decimal('amount', 14, 2);
            $t->timestampTz('occurred_at');
            $t->unique(['author_earning_id', 'type']);
            $t->index(['author_user_id', 'currency', 'type']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE commission_policies ADD CONSTRAINT commission_policy_values CHECK(percentage BETWEEN 0 AND 100 AND hold_days BETWEEN 0 AND 3650 AND (effective_until IS NULL OR effective_until>effective_from));
ALTER TABLE commission_policies ADD CONSTRAINT effective_default_no_overlap EXCLUDE USING gist (tstzrange(effective_from,effective_until,'[)') WITH &&) WHERE(is_active AND is_default);
ALTER TABLE order_items ADD CONSTRAINT seller_snapshot_values CHECK(seller_type IS NULL OR (commissionable_amount IS NOT NULL AND commissionable_amount=line_total AND ((seller_type='platform' AND origin='templaara' AND author_user_id IS NULL AND author_profile_id IS NULL AND commission_policy_id IS NULL AND commission_rate IS NULL AND commission_amount IS NULL AND author_amount IS NULL AND earning_hold_days IS NULL) OR (seller_type='author' AND origin='author' AND author_user_id IS NOT NULL AND commission_policy_id IS NOT NULL AND commission_rate IS NOT NULL AND commission_rate BETWEEN 0 AND 100 AND commission_amount IS NOT NULL AND author_amount IS NOT NULL AND commission_amount>=0 AND author_amount>=0 AND commission_amount+author_amount=commissionable_amount AND commission_amount=round(commissionable_amount*commission_rate/100,2) AND earning_hold_days IS NOT NULL AND earning_hold_days BETWEEN 0 AND 3650))));
ALTER TABLE author_earnings ADD CONSTRAINT earning_values CHECK(currency IN ('INR','USD','EUR','GBP') AND gross_amount>=0 AND commission_rate BETWEEN 0 AND 100 AND commission_amount>=0 AND author_amount>=0 AND author_amount+commission_amount=gross_amount AND commission_amount=round(gross_amount*commission_rate/100,2) AND hold_days BETWEEN 0 AND 3650 AND status IN ('pending','available') AND ((status='available')=(released_at IS NOT NULL)) AND (released_at IS NULL OR released_at>=available_at));
ALTER TABLE author_earning_ledger_entries ADD CONSTRAINT ledger_values CHECK(type IN ('earning_pending','earning_available') AND amount>=0 AND currency IN ('INR','USD','EUR','GBP'));
CREATE FUNCTION templaara_commission_policy_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Commission policy history is retained'; END IF;
 IF EXISTS(SELECT 1 FROM order_items WHERE commission_policy_id=OLD.id) AND (NEW.name,NEW.percentage,NEW.hold_days,NEW.effective_from,NEW.created_by) IS DISTINCT FROM (OLD.name,OLD.percentage,OLD.hold_days,OLD.effective_from,OLD.created_by) THEN RAISE EXCEPTION 'Used commission economics are immutable'; END IF;
 IF NEW.effective_until IS NOT NULL AND EXISTS(SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.commission_policy_id=OLD.id AND o.placed_at>=NEW.effective_until) THEN RAISE EXCEPTION 'Policy validity cannot contradict purchased terms'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER commission_policy_guard BEFORE UPDATE OR DELETE ON commission_policies FOR EACH ROW EXECUTE FUNCTION templaara_commission_policy_guard();
CREATE FUNCTION templaara_commission_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.seller_type IS NULL THEN RAISE EXCEPTION 'New purchases require a seller snapshot'; END IF;
 IF NOT EXISTS(SELECT 1 FROM products WHERE id=NEW.product_id AND origin=NEW.origin AND ((NEW.seller_type='platform' AND owner_id IS NULL) OR (NEW.seller_type='author' AND owner_id=NEW.author_user_id))) THEN RAISE EXCEPTION 'Purchased seller origin mismatch'; END IF;
 IF NEW.seller_type='author' AND NOT EXISTS(SELECT 1 FROM products p JOIN orders o ON o.id=NEW.order_id JOIN commission_policies c ON c.id=NEW.commission_policy_id WHERE p.id=NEW.product_id AND p.owner_id=NEW.author_user_id AND c.is_default AND c.is_active AND c.effective_from<=o.placed_at AND (c.effective_until IS NULL OR c.effective_until>o.placed_at) AND c.percentage=NEW.commission_rate AND c.hold_days=NEW.earning_hold_days) THEN RAISE EXCEPTION 'Seller/commission snapshot mismatch'; END IF;
 IF NEW.author_profile_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM author_profiles WHERE id=NEW.author_profile_id AND user_id=NEW.author_user_id) THEN RAISE EXCEPTION 'Seller profile mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER commission_item_guard BEFORE INSERT ON order_items FOR EACH ROW EXECUTE FUNCTION templaara_commission_item_guard();
CREATE FUNCTION templaara_earning_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Earnings cannot be deleted'; END IF;
 IF TG_OP='INSERT' AND NEW.status<>'pending' THEN RAISE EXCEPTION 'Earnings must start pending'; END IF;
 IF NEW.released_at IS NOT NULL AND NEW.released_at>clock_timestamp() THEN RAISE EXCEPTION 'Release cannot precede the actual hold deadline'; END IF;
 IF TG_OP='UPDATE' AND ((to_jsonb(NEW)-ARRAY['status','released_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','released_at','updated_at']) OR OLD.status<>'pending' OR NEW.status<>'available') THEN RAISE EXCEPTION 'Earning financial history is immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id JOIN payments p ON p.id=o.settled_payment_id WHERE i.id=NEW.order_item_id AND o.id=NEW.order_id AND o.status='completed' AND o.payment_status='paid' AND p.status='paid' AND p.order_id=o.id AND p.amount=o.grand_total AND p.currency=o.currency AND i.seller_type='author' AND i.author_user_id=NEW.author_user_id AND i.author_profile_id IS NOT DISTINCT FROM NEW.author_profile_id AND i.product_id=NEW.product_id AND i.product_release_id=NEW.product_release_id AND i.currency=NEW.currency AND i.commission_policy_id=NEW.commission_policy_id AND i.commission_rate=NEW.commission_rate AND i.commissionable_amount=NEW.gross_amount AND i.commission_amount=NEW.commission_amount AND i.author_amount=NEW.author_amount AND i.earning_hold_days=NEW.hold_days AND NEW.available_at=o.paid_at+NEW.hold_days*INTERVAL '1 day') THEN RAISE EXCEPTION 'Earning requires exact verified paid purchase terms'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER earning_guard BEFORE INSERT OR UPDATE OR DELETE ON author_earnings FOR EACH ROW EXECUTE FUNCTION templaara_earning_guard();
CREATE FUNCTION templaara_earning_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Financial ledger is append-only'; END IF;
 IF NOT EXISTS(SELECT 1 FROM author_earnings e WHERE e.id=NEW.author_earning_id AND e.author_user_id=NEW.author_user_id AND e.currency=NEW.currency AND e.author_amount=NEW.amount AND ((NEW.type='earning_pending' AND NEW.occurred_at=e.created_at) OR (NEW.type='earning_available' AND e.status='available' AND NEW.occurred_at=e.released_at))) THEN RAISE EXCEPTION 'Ledger earning identity or amount mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER earning_ledger_guard BEFORE INSERT OR UPDATE OR DELETE ON author_earning_ledger_entries FOR EACH ROW EXECUTE FUNCTION templaara_earning_ledger_guard();
CREATE FUNCTION templaara_earning_audit_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE author_earning_id=NEW.id AND type='earning_pending') OR (NEW.status='available' AND NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE author_earning_id=NEW.id AND type='earning_available')) THEN RAISE EXCEPTION 'Earning requires append-only ledger activity'; END IF;RETURN NEW; END; $$;
CREATE CONSTRAINT TRIGGER earning_audit_guard AFTER INSERT OR UPDATE ON author_earnings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_earning_audit_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Financial history requires reviewed forward migrations.');
    }
};
