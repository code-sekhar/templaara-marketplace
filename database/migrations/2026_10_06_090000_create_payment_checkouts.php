<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_checkouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider', 20);
            $t->string('provider_order_id', 100)->nullable();
            $t->string('provider_payment_id', 100)->nullable();
            $t->text('checkout_url')->nullable();
            $t->string('state', 20)->default('reserved');
            $t->uuid('claim_token')->nullable();
            $t->timestampTz('claimed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('available_at');
            $t->timestampTz('reconciled_at')->nullable();
            $t->string('problem_code', 40)->nullable();
            $t->timestampsTz();
            $t->unique(['provider', 'provider_order_id']);
            $t->unique(['provider', 'provider_payment_id']);
            $t->index(['state', 'available_at', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_checkouts ADD CONSTRAINT payment_checkout_values CHECK (provider IN ('stripe','razorpay') AND state IN ('reserved','creating','ready','uncertain','settled','failed','review') AND attempts>=0)");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_payment_checkout_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Payment operations history is retained'; END IF;
 IF NOT EXISTS(SELECT 1 FROM payments p WHERE p.id=NEW.payment_id AND p.provider=NEW.provider AND p.provider_payment_id='attempt-'||p.idempotency_key::text) THEN RAISE EXCEPTION 'External checkout requires exact reserved payment'; END IF;
 IF TG_OP='UPDATE' AND (NEW.payment_id<>OLD.payment_id OR NEW.provider<>OLD.provider OR
 (OLD.provider_order_id IS NOT NULL AND NEW.provider_order_id IS DISTINCT FROM OLD.provider_order_id) OR
 (OLD.provider_payment_id IS NOT NULL AND NEW.provider_payment_id IS DISTINCT FROM OLD.provider_payment_id)) THEN RAISE EXCEPTION 'Bound provider identities are immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER payment_checkout_guard BEFORE INSERT OR UPDATE OR DELETE ON payment_checkouts FOR EACH ROW EXECUTE FUNCTION templaara_payment_checkout_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('payment_checkouts')->exists()) {
            throw new RuntimeException('Cannot remove retained payment operations history.');
        }
        Schema::dropIfExists('payment_checkouts');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION templaara_payment_checkout_guard()');
        }
    }
};
