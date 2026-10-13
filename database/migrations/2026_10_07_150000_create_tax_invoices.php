<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->jsonb('tax_snapshot')->nullable();
            $t->string('customer_tax_id', 80)->nullable();
            $t->string('customer_gstin', 15)->nullable();
            $t->string('gst_state_code', 2)->nullable();
        });
        Schema::table('order_items', fn (Blueprint $t) => $t->jsonb('tax_snapshot')->nullable());
        Schema::create('invoice_sequences', function (Blueprint $t): void {
            $t->string('kind', 20);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number');
            $t->primary(['kind', 'year']);
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('number', 16)->unique();
            $t->string('kind', 20);
            $t->string('status', 20)->default('issued');
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('original_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $t->foreignId('refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('currency', 3);
            foreach (['subtotal', 'taxable_amount', 'tax_total', 'grand_total'] as $field) {
                $t->decimal($field, 14, 2);
            }
            $t->jsonb('customer_snapshot');
            $t->jsonb('issuer_snapshot');
            $t->jsonb('policy_snapshot');
            $t->string('order_number', 64);
            $t->string('payment_display', 40);
            $t->string('provenance', 30);
            $t->timestampTz('issued_at');
            $t->index(['issued_at', 'id']);
            $t->index(['currency', 'issued_at']);
        });
        Schema::create('invoice_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('original_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();
            $t->foreignId('refund_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('product_name');
            $t->string('offer_name');
            $t->string('version', 100);
            $t->string('license_type', 30);
            $t->string('seller_name');
            $t->string('origin', 20);
            $t->unsignedSmallInteger('quantity');
            foreach (['unit_price', 'taxable_amount', 'tax_amount', 'grand_total'] as $field) {
                $t->decimal($field, 14, 2);
            }
            $t->string('tax_type', 20);
            $t->decimal('tax_rate', 5, 2);
            $t->string('jurisdiction', 20);
            $t->jsonb('tax_snapshot');
            $t->unique(['invoice_id', 'order_item_id']);
            $t->index(['tax_type', 'invoice_id']);
        });
        Schema::create('invoice_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->timestampTz('occurred_at');
        });
        Schema::create('invoice_files', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $t->string('disk', 80);
            $t->string('path', 500);
            $t->string('checksum', 64);
            $t->unsignedBigInteger('bytes');
            $t->timestampTz('generated_at');
        });
        DB::statement("CREATE UNIQUE INDEX one_order_invoice ON invoices(order_id) WHERE kind='invoice'");
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE marketplace_settings DROP CONSTRAINT settings_known_group;
ALTER TABLE marketplace_settings ADD CONSTRAINT settings_known_group CHECK("group" IN ('general','commerce','authors','payouts','refunds','products','support','notifications','reporting','invoicing'));
ALTER TABLE orders DROP CONSTRAINT orders_values;
ALTER TABLE orders ADD CONSTRAINT orders_values CHECK(status IN ('pending_payment','payment_failed','completed','cancelled') AND payment_status IN ('pending','paid','failed','cancelled') AND subtotal>=0 AND discount_total=0 AND tax_total>=0 AND ((status='completed')=(payment_status='paid')) AND ((status='completed')=(paid_at IS NOT NULL)) AND ((tax_snapshot IS NULL AND tax_total=0 AND grand_total=subtotal) OR (tax_snapshot IS NOT NULL AND tax_snapshot ?& ARRAY['policy','tax_total','commercial_subtotal','grand_total'] AND tax_total=(tax_snapshot->>'tax_total')::numeric AND subtotal=(tax_snapshot->>'commercial_subtotal')::numeric AND grand_total=(tax_snapshot->>'grand_total')::numeric AND grand_total=subtotal+CASE WHEN (tax_snapshot->'policy'->>'prices_include_tax')::boolean THEN 0 ELSE tax_total END)));
ALTER TABLE invoices ADD CONSTRAINT invoice_values CHECK(kind IN ('invoice','credit_note') AND status='issued' AND currency IN ('INR','USD','EUR','GBP') AND subtotal>=0 AND taxable_amount>=0 AND tax_total>=0 AND grand_total=taxable_amount+tax_total AND ((kind='credit_note')=(refund_id IS NOT NULL)) AND ((kind='credit_note')=(original_invoice_id IS NOT NULL)) AND provenance IN ('checkout_snapshot','legacy_undetermined'));
ALTER TABLE invoice_items ADD CONSTRAINT invoice_line_values CHECK(tax_snapshot ?& ARRAY['taxable_amount','tax_amount','grand_total','type','rate','jurisdiction','components','reason'] AND quantity=1 AND unit_price>=0 AND taxable_amount>=0 AND tax_amount>=0 AND grand_total=taxable_amount+tax_amount AND tax_rate BETWEEN 0 AND 100 AND tax_type IN ('NONE','CGST_SGST','IGST','VAT','SALES_TAX','GST','OTHER'));
ALTER TABLE invoice_sequences ADD CONSTRAINT invoice_sequence_values CHECK(kind='document' AND last_number BETWEEN 1 AND 999999);
CREATE FUNCTION templaara_invoice_retained() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Issued documents are immutable and retained'; END $$;
CREATE TRIGGER invoice_retained BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION templaara_invoice_retained();
CREATE TRIGGER invoice_item_retained BEFORE UPDATE OR DELETE ON invoice_items FOR EACH ROW EXECUTE FUNCTION templaara_invoice_retained();
CREATE TRIGGER invoice_file_retained BEFORE UPDATE OR DELETE ON invoice_files FOR EACH ROW EXECUTE FUNCTION templaara_invoice_retained();
CREATE TRIGGER invoice_event_retained BEFORE UPDATE OR DELETE ON invoice_events FOR EACH ROW EXECUTE FUNCTION templaara_invoice_retained();
CREATE FUNCTION templaara_invoice_binding() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF NOT EXISTS(SELECT 1 FROM orders o JOIN payments p ON p.id=o.settled_payment_id WHERE o.id=NEW.order_id AND o.status='completed' AND o.payment_status='paid' AND p.status='paid' AND p.order_id=o.id AND p.amount=o.grand_total AND p.currency=o.currency AND NEW.currency=o.currency AND NEW.order_number=o.order_number) THEN RAISE EXCEPTION 'Invoice requires exact settled commerce'; END IF;
IF NEW.kind='invoice' AND NEW.grand_total<>(SELECT grand_total FROM orders WHERE id=NEW.order_id) THEN RAISE EXCEPTION 'Invoice paid amount mismatch'; END IF;
IF NEW.kind='credit_note' AND NOT EXISTS(SELECT 1 FROM invoices i JOIN refunds r ON r.order_id=i.order_id WHERE i.id=NEW.original_invoice_id AND i.kind='invoice' AND r.id=NEW.refund_id AND r.status='completed' AND r.currency=NEW.currency AND r.amount=NEW.grand_total AND i.order_id=NEW.order_id AND i.customer_snapshot=NEW.customer_snapshot AND i.issuer_snapshot=NEW.issuer_snapshot AND i.policy_snapshot=NEW.policy_snapshot) THEN RAISE EXCEPTION 'Credit requires completed exact refund and original snapshots'; END IF;
IF NEW.kind='invoice' AND EXISTS(SELECT 1 FROM orders o WHERE o.id=NEW.order_id AND (NEW.customer_snapshot IS DISTINCT FROM jsonb_build_object('customer_name',o.customer_name,'company_name',o.company_name,'billing_email',o.billing_email,'phone',o.phone,'address_line_1',o.address_line_1,'address_line_2',o.address_line_2,'city',o.city,'state_region',o.state_region,'postal_code',o.postal_code,'country_code',o.country_code,'customer_tax_id',o.customer_tax_id,'customer_gstin',o.customer_gstin,'gst_state_code',o.gst_state_code) OR (o.tax_snapshot IS NOT NULL AND NEW.policy_snapshot IS DISTINCT FROM o.tax_snapshot->'policy'))) THEN RAISE EXCEPTION 'Invoice identity/policy must match purchase snapshot'; END IF;
IF NEW.kind='invoice' AND (NEW.payment_display<>(SELECT p.provider FROM orders o JOIN payments p ON p.id=o.settled_payment_id WHERE o.id=NEW.order_id) OR EXISTS(SELECT 1 FROM jsonb_each(NEW.issuer_snapshot) AS e WHERE e.value IS DISTINCT FROM NEW.policy_snapshot->e.key) OR NEW.issuer_snapshot ?& ARRAY['legal_name','address_line_1','city','state_region','postal_code','country_code','contact_email']=false) THEN RAISE EXCEPTION 'Issuer and payment display must use authoritative evidence'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER invoice_binding BEFORE INSERT ON invoices FOR EACH ROW EXECUTE FUNCTION templaara_invoice_binding();
CREATE FUNCTION templaara_invoice_line_binding() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF NOT EXISTS(SELECT 1 FROM invoices d JOIN order_items i ON i.order_id=d.order_id WHERE d.id=NEW.invoice_id AND i.id=NEW.order_item_id AND NEW.unit_price=i.unit_price AND NEW.product_name=i.product_name AND NEW.offer_name=i.offer_name AND NEW.seller_name=i.seller_name AND NEW.origin=i.origin AND NEW.version=i.version AND NEW.license_type=i.license_type AND NEW.quantity=i.quantity AND NEW.grand_total=coalesce((i.tax_snapshot->>'grand_total')::numeric,i.line_total)) THEN RAISE EXCEPTION 'Invoice line purchased snapshot mismatch'; END IF;
IF (SELECT kind FROM invoices WHERE id=NEW.invoice_id)='credit_note' AND NOT EXISTS(SELECT 1 FROM invoice_items i JOIN invoices d ON d.original_invoice_id=i.invoice_id JOIN refund_items r ON r.refund_id=d.refund_id WHERE d.id=NEW.invoice_id AND i.id=NEW.original_item_id AND i.order_item_id=NEW.order_item_id AND r.id=NEW.refund_item_id AND r.order_item_id=NEW.order_item_id AND r.amount=NEW.grand_total AND i.tax_snapshot=NEW.tax_snapshot AND i.taxable_amount=NEW.taxable_amount AND i.tax_amount=NEW.tax_amount) THEN RAISE EXCEPTION 'Credit line must copy exact original tax'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER invoice_line_binding BEFORE INSERT ON invoice_items FOR EACH ROW EXECUTE FUNCTION templaara_invoice_line_binding();
CREATE FUNCTION templaara_invoice_totals() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE d invoices%ROWTYPE;BEGIN
SELECT * INTO d FROM invoices WHERE id=CASE WHEN TG_TABLE_NAME='invoices' THEN NEW.id ELSE (to_jsonb(NEW)->>'invoice_id')::bigint END;
IF d.subtotal<>(SELECT coalesce(sum(unit_price),0) FROM invoice_items WHERE invoice_id=d.id) OR d.taxable_amount<>(SELECT coalesce(sum(taxable_amount),0) FROM invoice_items WHERE invoice_id=d.id) OR d.tax_total<>(SELECT coalesce(sum(tax_amount),0) FROM invoice_items WHERE invoice_id=d.id) OR d.grand_total<>(SELECT coalesce(sum(grand_total),0) FROM invoice_items WHERE invoice_id=d.id) OR NOT EXISTS(SELECT 1 FROM invoice_events WHERE invoice_id=d.id AND occurred_at=d.issued_at AND type=d.kind||'_issued') THEN RAISE EXCEPTION 'Invoice lines/totals/audit must commit together'; END IF;
RETURN NULL; END $$;
CREATE CONSTRAINT TRIGGER invoice_totals AFTER INSERT ON invoices DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_invoice_totals();
CREATE CONSTRAINT TRIGGER invoice_item_totals AFTER INSERT ON invoice_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_invoice_totals();
ALTER TABLE invoice_files ADD CONSTRAINT invoice_file_values CHECK(bytes>0 AND checksum ~ '^[a-f0-9]{64}$');
CREATE FUNCTION templaara_invoice_tax_binding() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE t jsonb; o orders%ROWTYPE; d invoices%ROWTYPE; BEGIN
SELECT * INTO d FROM invoices WHERE id=NEW.invoice_id;
SELECT * INTO o FROM orders WHERE id=d.order_id;
SELECT tax_snapshot INTO t FROM order_items WHERE id=NEW.order_item_id;
IF NEW.taxable_amount<>(NEW.tax_snapshot->>'taxable_amount')::numeric OR NEW.tax_amount<>(NEW.tax_snapshot->>'tax_amount')::numeric OR NEW.grand_total<>(NEW.tax_snapshot->>'grand_total')::numeric OR NEW.tax_type<>NEW.tax_snapshot->>'type' OR NEW.tax_rate<>(NEW.tax_snapshot->>'rate')::numeric OR NEW.jurisdiction<>NEW.tax_snapshot->>'jurisdiction' OR jsonb_typeof(NEW.tax_snapshot->'components')<>'array' THEN RAISE EXCEPTION 'Tax projection does not match frozen evidence'; END IF;
IF NEW.tax_amount<>coalesce((SELECT sum((c->>'amount')::numeric) FROM jsonb_array_elements(NEW.tax_snapshot->'components') c),0) THEN RAISE EXCEPTION 'Tax components do not reconcile'; END IF;
IF d.kind='invoice' AND ((t IS NOT NULL AND t<>NEW.tax_snapshot) OR (t IS NULL AND (NEW.tax_type<>'NONE' OR NEW.tax_amount<>0 OR NEW.taxable_amount<>NEW.unit_price OR NEW.tax_snapshot->>'reason'<>'historical_tax_undetermined')) OR NEW.original_item_id IS NOT NULL OR NEW.refund_item_id IS NOT NULL) THEN RAISE EXCEPTION 'Invoice tax must reproduce purchased evidence'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER invoice_tax_binding BEFORE INSERT ON invoice_items FOR EACH ROW EXECUTE FUNCTION templaara_invoice_tax_binding();
CREATE FUNCTION templaara_order_tax_totals() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE o orders%ROWTYPE; BEGIN
SELECT * INTO o FROM orders WHERE id=CASE WHEN TG_TABLE_NAME='orders' THEN NEW.id ELSE (to_jsonb(NEW)->>'order_id')::bigint END;
IF o.tax_snapshot IS NOT NULL AND (o.tax_total<>(SELECT coalesce(sum((tax_snapshot->>'tax_amount')::numeric),0) FROM order_items WHERE order_id=o.id) OR o.grand_total<>(SELECT coalesce(sum((tax_snapshot->>'grand_total')::numeric),0) FROM order_items WHERE order_id=o.id) OR EXISTS(SELECT 1 FROM order_items WHERE order_id=o.id AND (tax_snapshot IS NULL OR line_total<>(tax_snapshot->>'commercial_amount')::numeric OR (tax_snapshot->>'grand_total')::numeric<>(tax_snapshot->>'taxable_amount')::numeric+(tax_snapshot->>'tax_amount')::numeric))) THEN RAISE EXCEPTION 'Order tax must commit with exact purchased lines'; END IF;
RETURN NULL; END $$;
CREATE CONSTRAINT TRIGGER order_tax_totals AFTER INSERT ON orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_order_tax_totals();
CREATE CONSTRAINT TRIGGER order_item_tax_totals AFTER INSERT ON order_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_order_tax_totals();
SQL);
        // Preserve every existing refund guard; extend only gross refund evidence for future taxed purchases.
        foreach (['templaara_refund_item_guard' => ['i.line_total=NEW.amount', "coalesce((i.tax_snapshot->>'grand_total')::numeric,i.line_total)=NEW.amount"],
            'templaara_refund_compensation_guard' => ['i.amount=NEW.gross_amount', "NEW.gross_amount=(SELECT line_total FROM order_items WHERE id=i.order_item_id) AND i.amount=coalesce((SELECT (tax_snapshot->>'grand_total')::numeric FROM order_items WHERE id=i.order_item_id),(SELECT line_total FROM order_items WHERE id=i.order_item_id))"]] as $function => [$before, $after]) {
            $sql = DB::selectOne('SELECT pg_get_functiondef(?::regproc) AS definition', [$function])->definition;
            if (substr_count($sql, $before) !== 1) {
                throw new LogicException('Unexpected financial guard; refuse an unsafe replacement.');
            }
            DB::connection()->getPdo()->exec(str_replace($before, $after, $sql));
        }
    }

    public function down(): void
    {
        throw new LogicException('Issued tax documents require reviewed forward migrations.');
    }
};
