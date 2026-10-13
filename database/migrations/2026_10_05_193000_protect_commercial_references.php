<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cart_items' => ['product_id', 'product_offer_id'], 'order_items' => ['product_id', 'product_offer_id', 'product_release_id'], 'payment_events' => ['payment_id'], 'orders' => ['settled_payment_id']] as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) {
                    $t->index($column);
                }
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_pair CHECK ((status,payment_status) IN (('pending_payment','pending'),('payment_failed','failed'),('completed','paid'),('cancelled','cancelled')) AND ((status='cancelled') = (cancelled_at IS NOT NULL)) AND ((status='completed') = (settled_payment_id IS NOT NULL)) AND ((status='completed') = (invoice_number IS NOT NULL)))");
            DB::statement("ALTER TABLE cart_items ADD CONSTRAINT cart_observed_values CHECK (observed_price>=0 AND observed_currency IN ('INR','USD','EUR','GBP') AND terms_hash ~ '^[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_event_values CHECK (status IN ('processed','ignored') AND event_type IN ('paid','failed') AND payload_hash ~ '^[a-f0-9]{64}$')");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_commercial_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'order_items' THEN
        IF NOT EXISTS (SELECT 1 FROM product_offers WHERE id=NEW.product_offer_id AND product_id=NEW.product_id)
          OR NOT EXISTS (SELECT 1 FROM product_releases WHERE id=NEW.product_release_id AND product_id=NEW.product_id)
          OR NOT EXISTS (SELECT 1 FROM orders WHERE id=NEW.order_id AND currency=NEW.currency) THEN
          RAISE EXCEPTION 'Order item references or currency mismatch';
        END IF;
    ELSIF TG_TABLE_NAME = 'payments' THEN
        IF NOT EXISTS (SELECT 1 FROM orders WHERE id=NEW.order_id AND currency=NEW.currency AND grand_total=NEW.amount) THEN
            RAISE EXCEPTION 'Payment amount or currency mismatch';
        END IF;
    ELSIF NEW.settled_payment_id IS NOT NULL THEN
        IF NOT EXISTS (SELECT 1 FROM payments WHERE id=NEW.settled_payment_id AND order_id=NEW.id AND status='paid' AND amount=NEW.grand_total AND currency=NEW.currency) THEN
            RAISE EXCEPTION 'Order settlement reference mismatch';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER items_reference_guard BEFORE INSERT ON order_items FOR EACH ROW EXECUTE FUNCTION templaara_commercial_reference_guard();
CREATE TRIGGER payments_reference_guard BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION templaara_commercial_reference_guard();
CREATE TRIGGER orders_settlement_guard BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION templaara_commercial_reference_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER orders_settlement_guard ON orders');
            DB::statement('DROP TRIGGER payments_reference_guard ON payments');
            DB::statement('DROP TRIGGER items_reference_guard ON order_items');
            DB::statement('DROP FUNCTION templaara_commercial_reference_guard()');
            foreach (['orders' => 'orders_status_pair', 'cart_items' => 'cart_observed_values', 'payment_events' => 'payment_event_values'] as $table => $constraint) {
                DB::statement("ALTER TABLE $table DROP CONSTRAINT $constraint");
            }
        }
        foreach (['cart_items' => ['product_id', 'product_offer_id'], 'order_items' => ['product_id', 'product_offer_id', 'product_release_id'], 'payment_events' => ['payment_id'], 'orders' => ['settled_payment_id']] as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) {
                    $t->dropIndex([$column]);
                }
            });
        }
    }
};
