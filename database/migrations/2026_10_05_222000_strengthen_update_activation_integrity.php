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
CREATE FUNCTION templaara_activation_terms_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF NOT EXISTS(SELECT 1 FROM product_offers WHERE id=NEW.product_offer_id AND activation_limit=NEW.activation_limit) THEN RAISE EXCEPTION 'Purchase activation terms mismatch'; END IF; RETURN NEW; END; $$;
CREATE TRIGGER activation_terms_guard BEFORE INSERT ON order_items FOR EACH ROW EXECUTE FUNCTION templaara_activation_terms_guard();
ALTER TABLE license_activation_events ADD CONSTRAINT activation_action CHECK(action IN ('activated','reactivated','deactivated'));
ALTER TABLE license_activations ADD CONSTRAINT activation_times CHECK((deactivated_at IS NULL OR deactivated_at>=activated_at) AND (last_validated_at IS NULL OR last_validated_at>=created_at));
CREATE FUNCTION templaara_activation_purchase_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.status='active' AND NOT EXISTS(SELECT 1 FROM licenses l JOIN entitlements e ON e.id=l.entitlement_id JOIN orders o ON o.id=e.order_id JOIN payments p ON p.id=o.settled_payment_id WHERE l.id=NEW.license_id AND l.status='active' AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at>now()) AND e.status='active' AND e.revoked_at IS NULL AND (e.expires_at IS NULL OR e.expires_at>now()) AND o.status='completed' AND o.payment_status='paid' AND p.status='paid' AND p.order_id=o.id AND p.amount=o.grand_total AND p.currency=o.currency) THEN RAISE EXCEPTION 'Active activation requires active paid access'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER activation_purchase_guard BEFORE INSERT OR UPDATE ON license_activations FOR EACH ROW EXECUTE FUNCTION templaara_activation_purchase_guard();
CREATE FUNCTION templaara_activation_audit_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='INSERT' OR NEW.status<>OLD.status THEN
 IF NOT EXISTS(SELECT 1 FROM license_activation_events WHERE license_activation_id=NEW.id AND occurred_at>=NEW.updated_at AND action=CASE WHEN NEW.status='deactivated' THEN 'deactivated' WHEN TG_OP='INSERT' THEN 'activated' ELSE 'reactivated' END) THEN RAISE EXCEPTION 'Activation state change requires audit'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER activation_audit_guard AFTER INSERT OR UPDATE ON license_activations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_activation_audit_guard();
CREATE FUNCTION templaara_revision_audit_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.status<>OLD.status AND NOT EXISTS(SELECT 1 FROM product_lifecycle_events WHERE product_revision_id=NEW.id AND from_status=OLD.status AND to_status=NEW.status AND occurred_at>=NEW.updated_at) THEN RAISE EXCEPTION 'Revision transition requires audit'; END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER revision_audit_guard AFTER UPDATE ON product_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_revision_audit_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed forward migration required.');
    }
};
