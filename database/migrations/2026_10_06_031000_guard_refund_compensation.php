<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        } DB::unprepared(<<<'SQL'
ALTER TABLE author_earning_ledger_entries DROP CONSTRAINT ledger_values;
ALTER TABLE author_earning_ledger_entries ADD CONSTRAINT ledger_values CHECK(amount>=0 AND currency IN ('INR','USD','EUR','GBP') AND ((type IN ('earning_pending','earning_available') AND payout_allocation_id IS NULL AND refund_item_id IS NULL) OR (type IN ('payout_reserved','payout_released','payout_paid') AND payout_allocation_id IS NOT NULL AND refund_item_id IS NULL AND amount>0) OR (type='refund_reversal' AND refund_item_id IS NOT NULL AND payout_allocation_id IS NULL)));
CREATE OR REPLACE FUNCTION templaara_earning_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Financial ledger append-only'; END IF;
 IF NEW.type IN ('earning_pending','earning_available') THEN
 IF NOT EXISTS(SELECT 1 FROM author_earnings e WHERE e.id=NEW.author_earning_id AND e.author_user_id=NEW.author_user_id AND e.currency=NEW.currency AND e.author_amount=NEW.amount AND ((NEW.type='earning_pending' AND NEW.occurred_at=e.created_at) OR (NEW.type='earning_available' AND e.status='available' AND NEW.occurred_at=e.released_at))) THEN RAISE EXCEPTION 'Earning ledger mismatch'; END IF;
 ELSIF NEW.type='refund_reversal' THEN
 IF NOT EXISTS(SELECT 1 FROM refund_compensations c JOIN refund_items i ON i.id=c.refund_item_id JOIN refunds r ON r.id=i.refund_id WHERE c.refund_item_id=NEW.refund_item_id AND c.author_earning_id=NEW.author_earning_id AND c.author_user_id=NEW.author_user_id AND c.currency=NEW.currency AND c.author_amount=NEW.amount AND r.status='completed' AND NEW.occurred_at=r.completed_at) THEN RAISE EXCEPTION 'Refund reversal mismatch'; END IF;
 ELSE
 IF NOT EXISTS(SELECT 1 FROM payout_allocations a JOIN payout_requests p ON p.id=a.payout_request_id WHERE a.id=NEW.payout_allocation_id AND a.author_earning_id=NEW.author_earning_id AND a.amount=NEW.amount AND p.author_user_id=NEW.author_user_id AND p.currency=NEW.currency AND ((NEW.type='payout_reserved' AND p.status='requested' AND NEW.occurred_at=p.requested_at) OR (NEW.type='payout_paid' AND p.status='paid' AND NEW.occurred_at=p.paid_at) OR (NEW.type='payout_released' AND p.status IN ('failed','cancelled') AND NEW.occurred_at=coalesce(p.failed_at,p.cancelled_at)))) THEN RAISE EXCEPTION 'Payout ledger mismatch'; END IF;
 END IF;RETURN NEW;END; $$;

CREATE FUNCTION templaara_refund_earning_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS(SELECT 1 FROM refund_compensations WHERE author_earning_id=NEW.id) THEN RAISE EXCEPTION 'Refunded earning cannot be released'; END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_earning_guard BEFORE UPDATE ON author_earnings FOR EACH ROW EXECUTE FUNCTION templaara_refund_earning_guard();
CREATE FUNCTION templaara_refund_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS(SELECT 1 FROM refund_compensations WHERE author_earning_id=NEW.author_earning_id) THEN RAISE EXCEPTION 'Refunded earning cannot fund payout'; END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_allocation_guard BEFORE INSERT ON payout_allocations FOR EACH ROW EXECUTE FUNCTION templaara_refund_allocation_guard();
CREATE FUNCTION templaara_refund_payout_balance_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE eligible numeric;BEGIN
 PERFORM 1 FROM users WHERE id=NEW.author_user_id FOR UPDATE;
 SELECT coalesce(sum(CASE l.type WHEN 'earning_available' THEN l.amount WHEN 'payout_reserved' THEN -l.amount WHEN 'payout_released' THEN l.amount WHEN 'refund_reversal' THEN CASE WHEN c.earning_bucket='available' THEN -l.amount ELSE 0 END ELSE 0 END),0) INTO eligible FROM author_earning_ledger_entries l LEFT JOIN refund_compensations c ON c.refund_item_id=l.refund_item_id WHERE l.author_user_id=NEW.author_user_id AND l.currency=NEW.currency;
 IF NEW.amount>eligible THEN RAISE EXCEPTION 'Payout cannot bypass refund adjustment'; END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_payout_balance_guard BEFORE INSERT ON payout_requests FOR EACH ROW EXECUTE FUNCTION templaara_refund_payout_balance_guard();
CREATE FUNCTION templaara_refund_access_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS(SELECT 1 FROM refund_compensations WHERE order_item_id=NEW.order_item_id) AND (NEW.status<>'revoked' OR NEW.revoked_at IS NULL OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)) THEN RAISE EXCEPTION 'Refunded purchase cannot regain access'; END IF;RETURN NEW;END;$$;
CREATE TRIGGER refund_entitlement_guard BEFORE UPDATE ON entitlements FOR EACH ROW EXECUTE FUNCTION templaara_refund_access_guard();
CREATE TRIGGER refund_license_guard BEFORE UPDATE ON licenses FOR EACH ROW EXECUTE FUNCTION templaara_refund_access_guard();
CREATE FUNCTION templaara_refund_completion_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE rid bigint;r refunds%ROWTYPE;BEGIN
 IF TG_TABLE_NAME='refunds' THEN rid=NEW.id;
 ELSIF TG_TABLE_NAME='refund_items' THEN rid=NEW.refund_id;
 ELSIF TG_TABLE_NAME='refund_compensations' THEN SELECT refund_id INTO rid FROM refund_items WHERE id=NEW.refund_item_id;
 ELSIF TG_TABLE_NAME='author_earning_ledger_entries' THEN SELECT refund_id INTO rid FROM refund_items WHERE id=NEW.refund_item_id;
 ELSE SELECT refund_id INTO rid FROM refund_items WHERE order_item_id=NEW.order_item_id ORDER BY id DESC LIMIT 1;END IF;
 IF rid IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO r FROM refunds WHERE id=rid;
 IF coalesce((SELECT sum(amount) FROM refund_items WHERE refund_id=rid),0)<>r.amount OR NOT EXISTS(SELECT 1 FROM refund_events WHERE refund_id=rid AND status=r.status) THEN RAISE EXCEPTION 'Refund requires exact items and complete audit'; END IF;
 IF EXISTS(SELECT 1 FROM refund_items i LEFT JOIN purchase_refund_states s ON s.order_item_id=i.order_item_id WHERE i.refund_id=rid AND (s.order_item_id IS NULL OR (r.status IN ('requested','approved','processing') AND s.active_refund_id IS DISTINCT FROM rid) OR (r.status='completed' AND s.completed_refund_item_id IS DISTINCT FROM i.id) OR (r.status IN ('failed','cancelled') AND s.active_refund_id=rid))) THEN RAISE EXCEPTION 'Refund requires matching purchase claims'; END IF;
 IF r.status='completed' AND EXISTS(SELECT 1 FROM refund_items i LEFT JOIN refund_compensations c ON c.refund_item_id=i.id LEFT JOIN entitlements e ON e.order_item_id=i.order_item_id LEFT JOIN licenses l ON l.entitlement_id=e.id WHERE i.refund_id=rid AND (c.id IS NULL OR e.id IS NULL OR l.id IS NULL OR e.status<>'revoked' OR l.status<>'revoked' OR (i.author_earning_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM author_earning_ledger_entries WHERE refund_item_id=i.id AND type='refund_reversal')))) THEN RAISE EXCEPTION 'Completed refund requires compensation and access revocation'; END IF;
 RETURN NEW;END;$$;
CREATE CONSTRAINT TRIGGER refund_completion_guard AFTER INSERT OR UPDATE ON refunds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_refund_completion_guard();
CREATE CONSTRAINT TRIGGER refund_items_completion_guard AFTER INSERT ON refund_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_refund_completion_guard();
CREATE CONSTRAINT TRIGGER refund_compensations_completion_guard AFTER INSERT ON refund_compensations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_refund_completion_guard();
CREATE CONSTRAINT TRIGGER refund_states_completion_guard AFTER INSERT OR UPDATE ON purchase_refund_states DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_refund_completion_guard();
CREATE CONSTRAINT TRIGGER refund_ledger_completion_guard AFTER INSERT ON author_earning_ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_refund_completion_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Financial reversal integrity requires reviewed forward migrations.');
    }
};
