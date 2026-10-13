<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('currency', 3)->nullable();
            $t->uuid('checkout_key')->unique();
            $t->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_offer_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('observed_price', 12, 2);
            $t->string('observed_currency', 3);
            $t->string('observed_name');
            $t->char('terms_hash', 64);
            $t->timestamps();
            $t->unique(['cart_id', 'product_id']);
        });
        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('order_number', 64)->unique();
            $t->string('invoice_number', 64)->nullable()->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('checkout_key')->unique();
            $t->string('status', 30);
            $t->string('payment_status', 20);
            $t->string('currency', 3);
            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $money) {
                $t->decimal($money, 14, 2);
            }
            $t->string('customer_name');
            $t->string('company_name')->nullable();
            $t->string('billing_email');
            $t->string('phone', 40)->nullable();
            $t->string('address_line_1');
            $t->string('address_line_2')->nullable();
            $t->string('city', 100);
            $t->string('state_region', 100);
            $t->string('postal_code', 30);
            $t->string('country_code', 2);
            $t->timestampTz('placed_at');
            $t->timestampTz('paid_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'placed_at', 'id']);
            $t->index(['status', 'payment_status', 'currency', 'placed_at']);
        });
        Schema::create('order_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_offer_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_release_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('source_cart_item_id');
            $t->char('cart_terms_hash', 64);
            $t->string('product_name');
            $t->string('product_slug', 200);
            $t->string('offer_name');
            $t->string('license_type', 30);
            $t->decimal('unit_price', 14, 2);
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->decimal('line_total', 14, 2);
            $t->string('currency', 3);
            $t->unsignedInteger('support_period_days')->nullable();
            $t->unsignedInteger('updates_period_days')->nullable();
            $t->string('version', 50);
            $t->string('origin', 20);
            $t->string('seller_name')->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'product_id']);
        });
        Schema::create('payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('provider', 50);
            $t->string('provider_payment_id', 100);
            $t->string('provider_order_id', 100);
            $t->string('status', 20);
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->uuid('idempotency_key')->unique();
            $t->string('failure_code', 100)->nullable();
            $t->string('failure_message', 500)->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_payment_id']);
            $t->index(['order_id', 'status', 'id']);
        });
        Schema::table('orders', fn (Blueprint $t) => $t->foreignId('settled_payment_id')->nullable()->constrained('payments')->restrictOnDelete());
        Schema::create('payment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->string('provider', 50);
            $t->string('provider_event_id', 100);
            $t->string('event_type', 30);
            $t->char('payload_hash', 64);
            $t->string('status', 20);
            $t->timestampTz('processed_at');
            $t->timestamps();
            $t->unique(['provider', 'provider_event_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['carts', 'orders', 'order_items', 'payments'] as $table) {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_currency CHECK (currency IN ('INR','USD','EUR','GBP'))");
            }
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_values CHECK (status IN ('pending_payment','payment_failed','completed','cancelled') AND payment_status IN ('pending','paid','failed','cancelled') AND subtotal >= 0 AND discount_total = 0 AND tax_total = 0 AND grand_total = subtotal AND ((status = 'completed') = (payment_status = 'paid')) AND ((status = 'completed') = (paid_at IS NOT NULL)))");
            DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_values CHECK (quantity = 1 AND unit_price >= 0 AND line_total = unit_price AND license_type IN ('single_business','multi_business','extended') AND origin IN ('author','templaara'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_values CHECK (amount >= 0 AND status IN ('pending','paid','failed','cancelled') AND ((status = 'paid') = (paid_at IS NOT NULL)))");
            DB::statement("CREATE UNIQUE INDEX payments_one_settlement ON payments(order_id) WHERE status = 'paid'");
            DB::statement("CREATE UNIQUE INDEX payments_one_pending ON payments(order_id) WHERE status = 'pending'");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_commercial_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_TABLE_NAME = 'order_items' THEN RAISE EXCEPTION 'Commercial history is retained and immutable'; END IF;
    IF TG_TABLE_NAME = 'orders' AND
      (to_jsonb(NEW) - ARRAY['status','payment_status','paid_at','cancelled_at','invoice_number','settled_payment_id','updated_at']) IS DISTINCT FROM
      (to_jsonb(OLD) - ARRAY['status','payment_status','paid_at','cancelled_at','invoice_number','settled_payment_id','updated_at']) THEN
      RAISE EXCEPTION 'Order commercial snapshot is immutable';
    END IF;
    IF TG_TABLE_NAME = 'payments' AND
      (to_jsonb(NEW) - ARRAY['status','failure_code','failure_message','paid_at','updated_at']) IS DISTINCT FROM
      (to_jsonb(OLD) - ARRAY['status','failure_code','failure_message','paid_at','updated_at']) THEN
      RAISE EXCEPTION 'Payment identity and amount are immutable';
    END IF;
    IF TG_TABLE_NAME = 'payment_events' THEN RAISE EXCEPTION 'Payment events are immutable'; END IF;
    IF TG_TABLE_NAME = 'orders' AND OLD.status IN ('completed','cancelled') AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Terminal orders are immutable'; END IF;
    IF TG_TABLE_NAME = 'payments' AND OLD.status <> 'pending' AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Terminal payments are immutable'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER orders_commercial_guard BEFORE UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION templaara_commercial_guard();
CREATE TRIGGER items_commercial_guard BEFORE UPDATE OR DELETE ON order_items FOR EACH ROW EXECUTE FUNCTION templaara_commercial_guard();
CREATE TRIGGER payments_commercial_guard BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION templaara_commercial_guard();
CREATE TRIGGER payment_events_commercial_guard BEFORE UPDATE OR DELETE ON payment_events FOR EACH ROW EXECUTE FUNCTION templaara_commercial_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->exists()) {
            throw new RuntimeException('Cannot remove retained commercial orders.');
        }
        Schema::dropIfExists('payment_events');
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('settled_payment_id'));
        foreach (['payments', 'order_items', 'orders', 'cart_items', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION templaara_commercial_guard()');
        }
    }
};
