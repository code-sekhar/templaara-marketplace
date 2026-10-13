<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('ticket_number', 64)->unique();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('seller_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('product_name');
            $t->string('version', 100);
            $t->string('offer_name');
            $t->string('license_type', 40);
            $t->unsignedInteger('support_period_days');
            $t->timestampTz('paid_at');
            $t->timestampTz('support_ends_at');
            $t->string('subject', 200);
            $t->string('category', 40);
            $t->string('priority', 20);
            $t->string('status', 30);
            $t->boolean('is_escalated')->default(false);
            $t->boolean('admin_locked')->default(false);
            $t->unsignedInteger('event_sequence');
            $t->unsignedInteger('customer_last_read_sequence')->default(0);
            $t->unsignedInteger('seller_last_read_sequence')->default(0);
            foreach (['last_message_at', 'customer_last_read_at', 'seller_last_read_at', 'resolved_at', 'closed_at', 'reopened_at'] as $k) {
                $t->timestampTz($k)->nullable();
            }$t->timestampsTz();
            $t->unique(['customer_user_id', 'request_key']);
            $t->index(['customer_user_id', 'last_message_at', 'id']);
            $t->index(['seller_user_id', 'status', 'last_message_at', 'id']);
            $t->index(['order_item_id', 'status']);
            $t->index(['status', 'priority', 'last_message_at']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $t->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->string('sender_type', 20);
            $t->boolean('is_internal')->default(false);
            $t->text('body');
            $t->unsignedInteger('sequence');
            $t->timestampTz('created_at');
            $t->unique(['ticket_id', 'sender_user_id', 'request_key']);
            $t->unique(['ticket_id', 'sequence']);
            $t->index(['ticket_id', 'is_internal', 'id']);
        });
        Schema::create('support_attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('message_id')->constrained('support_messages')->restrictOnDelete();
            $t->foreignId('uploader_user_id')->constrained('users')->restrictOnDelete();
            $t->string('original_name', 200);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->char('checksum', 64);
            $t->string('disk', 100);
            $t->string('path', 255);
            $t->timestampTz('created_at');
            $t->unique(['disk', 'path']);
        });
        Schema::create('support_ticket_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('type', 40);
            $t->string('previous_status', 30)->nullable();
            $t->string('status', 30);
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at');
            $t->unique(['ticket_id', 'sequence']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_support_purchase(customer bigint, item bigint, require_period boolean DEFAULT true) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS(SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id JOIN payments p ON p.id=o.settled_payment_id JOIN entitlements e ON e.order_item_id=i.id JOIN licenses l ON l.entitlement_id=e.id JOIN product_artifacts a ON a.id=e.product_artifact_id JOIN product_releases r ON r.id=a.product_release_id
WHERE i.id=item AND o.user_id=customer AND o.status='completed' AND o.payment_status='paid' AND o.paid_at IS NOT NULL AND p.order_id=o.id AND p.status='paid' AND p.amount=o.grand_total AND p.currency=o.currency
AND e.user_id=o.user_id AND e.order_id=o.id AND e.product_id=i.product_id AND e.product_offer_id=i.product_offer_id AND e.product_release_id=i.product_release_id AND e.status='active' AND e.revoked_at IS NULL AND (e.expires_at IS NULL OR e.expires_at>now())
AND l.user_id=o.user_id AND l.order_item_id=i.id AND l.product_id=i.product_id AND l.product_release_id=i.product_release_id AND l.status='active' AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at>now())
AND a.type='source_package' AND a.product_release_id=i.product_release_id AND r.product_id=i.product_id AND r.version=i.version
AND NOT EXISTS(SELECT 1 FROM purchase_refund_states s WHERE s.order_item_id=i.id AND s.completed_refund_item_id IS NOT NULL)
AND (NOT require_period OR (i.support_period_days>0 AND now()<o.paid_at+i.support_period_days*INTERVAL '1 day')));
$$;
ALTER TABLE support_tickets ADD CONSTRAINT support_ticket_values CHECK(status IN ('awaiting_seller','awaiting_customer','resolved','closed') AND category IN ('installation','configuration','bug','usage_question','compatibility','license_activation','update','other') AND priority IN ('low','normal','high') AND length(trim(subject)) BETWEEN 3 AND 200 AND event_sequence>0 AND customer_last_read_sequence BETWEEN 0 AND event_sequence AND seller_last_read_sequence BETWEEN 0 AND event_sequence AND support_period_days>0 AND created_at>=paid_at AND created_at<support_ends_at AND support_ends_at=paid_at+support_period_days*INTERVAL '1 day' AND ((status='closed')=(closed_at IS NOT NULL)) AND (status<>'resolved' OR resolved_at IS NOT NULL) AND (NOT admin_locked OR status='closed') AND (closed_at IS NULL OR closed_at>=created_at) AND (resolved_at IS NULL OR resolved_at>=created_at) AND (reopened_at IS NULL OR reopened_at>=created_at));
ALTER TABLE support_messages ADD CONSTRAINT support_message_values CHECK(length(trim(body)) BETWEEN 1 AND 10000 AND sender_type IN ('customer','seller','admin') AND (NOT is_internal OR sender_type='admin') AND sequence>0);
ALTER TABLE support_attachments ADD CONSTRAINT support_attachment_values CHECK(size_bytes>0 AND size_bytes<=10485760 AND checksum~'^[a-f0-9]{64}$' AND path~'^support/[a-f0-9-]+\.(png|jpg|jpeg|webp|pdf|txt|log)$' AND mime_type IN ('image/png','image/jpeg','image/webp','application/pdf','text/plain'));
ALTER TABLE support_ticket_events ADD CONSTRAINT support_event_values CHECK(sequence>0 AND status IN ('awaiting_seller','awaiting_customer','resolved','closed') AND type IN ('created','customer_replied','seller_replied','admin_replied','resolved','closed','reopened','priority_changed','escalated','deescalated','purchase_refunded','internal_note_added'));
CREATE FUNCTION templaara_support_ticket_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE i order_items%ROWTYPE;o orders%ROWTYPE;BEGIN
IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Support history retained';END IF;
PERFORM id FROM users WHERE id=NEW.customer_user_id FOR UPDATE;
IF TG_OP='INSERT' THEN
SELECT * INTO i FROM order_items WHERE id=NEW.order_item_id;SELECT * INTO o FROM orders WHERE id=i.order_id;
IF NOT templaara_support_purchase(NEW.customer_user_id,NEW.order_item_id) OR NEW.product_id<>i.product_id OR o.user_id<>NEW.customer_user_id OR NEW.seller_user_id IS DISTINCT FROM i.author_user_id OR i.seller_type IS NULL OR (NEW.product_name,NEW.version,NEW.offer_name,NEW.license_type,NEW.support_period_days,NEW.paid_at) IS DISTINCT FROM (i.product_name,i.version,i.offer_name,i.license_type,i.support_period_days,o.paid_at) OR NEW.status<>'awaiting_seller' OR NEW.event_sequence<>1 OR NEW.admin_locked OR NEW.is_escalated THEN RAISE EXCEPTION 'Support requires exact qualifying purchase snapshot';END IF;
ELSE
IF (to_jsonb(NEW)-ARRAY['status','priority','is_escalated','admin_locked','event_sequence','last_message_at','customer_last_read_at','seller_last_read_at','customer_last_read_sequence','seller_last_read_sequence','resolved_at','closed_at','reopened_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','priority','is_escalated','admin_locked','event_sequence','last_message_at','customer_last_read_at','seller_last_read_at','customer_last_read_sequence','seller_last_read_sequence','resolved_at','closed_at','reopened_at','updated_at']) THEN RAISE EXCEPTION 'Support commercial identity immutable';END IF;
IF (to_jsonb(NEW)-ARRAY['customer_last_read_at','seller_last_read_at','customer_last_read_sequence','seller_last_read_sequence','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['customer_last_read_at','seller_last_read_at','customer_last_read_sequence','seller_last_read_sequence','updated_at']) AND NEW.event_sequence<>OLD.event_sequence+1 THEN RAISE EXCEPTION 'Support requires new audit sequence';END IF;
IF OLD.status='closed' AND NEW.status<>'closed' THEN RAISE EXCEPTION 'Closed support is terminal';END IF;
IF OLD.status='resolved' AND NEW.status IN ('awaiting_seller','awaiting_customer') AND NOT templaara_support_purchase(NEW.customer_user_id,NEW.order_item_id) THEN RAISE EXCEPTION 'Reopening requires current support';END IF;
END IF;
IF NEW.status<>'closed' AND EXISTS(SELECT 1 FROM purchase_refund_states s WHERE s.order_item_id=NEW.order_item_id AND s.completed_refund_item_id IS NOT NULL) THEN RAISE EXCEPTION 'Refunded support must be closed';END IF;
RETURN NEW;END;$$;
CREATE TRIGGER support_ticket_guard BEFORE INSERT OR UPDATE OR DELETE ON support_tickets FOR EACH ROW EXECUTE FUNCTION templaara_support_ticket_guard();
CREATE FUNCTION templaara_support_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE t support_tickets%ROWTYPE;BEGIN
IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Support messages immutable';END IF;
SELECT * INTO t FROM support_tickets WHERE id=NEW.ticket_id;
IF NEW.sequence<>t.event_sequence OR NEW.created_at<t.created_at OR NOT (NEW.sequence=1 OR t.status IN ('awaiting_seller','awaiting_customer') OR NEW.is_internal) THEN RAISE EXCEPTION 'Support message state mismatch';END IF;
IF (NEW.sender_type='customer' AND (NEW.sender_user_id<>t.customer_user_id OR NEW.is_internal)) OR (NEW.sender_type='seller' AND (NEW.sender_user_id IS DISTINCT FROM t.seller_user_id OR NEW.is_internal OR NOT templaara_review_permission(NEW.sender_user_id,'products.view') OR NOT EXISTS(SELECT 1 FROM model_has_roles m JOIN roles r ON r.id=m.role_id WHERE m.model_id=NEW.sender_user_id AND m.model_type='App\Models\User' AND r.name='author' AND r.guard_name='web'))) OR (NEW.sender_type='admin' AND NOT templaara_review_permission(NEW.sender_user_id,'support.manage')) THEN RAISE EXCEPTION 'Support sender unauthorized';END IF;
RETURN NEW;END;$$;
CREATE TRIGGER support_message_guard BEFORE INSERT OR UPDATE OR DELETE ON support_messages FOR EACH ROW EXECUTE FUNCTION templaara_support_message_guard();
CREATE FUNCTION templaara_support_attachment_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Support attachments retained immutable';END IF;
IF NOT EXISTS(SELECT 1 FROM support_messages m WHERE m.id=NEW.message_id AND m.sender_user_id=NEW.uploader_user_id) OR NEW.disk IN ('public','product_s3_public') THEN RAISE EXCEPTION 'Support attachment identity invalid';END IF;
RETURN NEW;END;$$;
CREATE TRIGGER support_attachment_guard BEFORE INSERT OR UPDATE OR DELETE ON support_attachments FOR EACH ROW EXECUTE FUNCTION templaara_support_attachment_guard();
CREATE FUNCTION templaara_support_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE t support_tickets%ROWTYPE;BEGIN
IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Support audit append only';END IF;SELECT * INTO t FROM support_tickets WHERE id=NEW.ticket_id;
IF NEW.sequence<>t.event_sequence OR NEW.status<>t.status OR NEW.occurred_at<t.created_at OR (NEW.sequence=1 AND (NEW.type<>'created' OR NEW.previous_status IS NOT NULL)) OR (NEW.sequence>1 AND NEW.previous_status IS DISTINCT FROM (SELECT status FROM support_ticket_events WHERE ticket_id=t.id AND sequence=NEW.sequence-1)) THEN RAISE EXCEPTION 'Support audit sequence mismatch';END IF;
IF NEW.type<>'purchase_refunded' AND NEW.actor_id IS NULL THEN RAISE EXCEPTION 'Support action requires authenticated actor';END IF;
IF NEW.type IN ('created','customer_replied') AND NEW.actor_id IS DISTINCT FROM t.customer_user_id THEN RAISE EXCEPTION 'Support customer actor mismatch';END IF;
IF NEW.type='seller_replied' AND NEW.actor_id IS DISTINCT FROM t.seller_user_id THEN RAISE EXCEPTION 'Support seller actor mismatch';END IF;
IF NEW.type IN ('admin_replied','internal_note_added','priority_changed','deescalated') AND (NEW.actor_id IS NULL OR NOT templaara_review_permission(NEW.actor_id,'support.manage')) THEN RAISE EXCEPTION 'Support management permission required';END IF;
IF NEW.type='purchase_refunded' AND (NEW.actor_id IS NOT NULL OR t.status<>'closed' OR NOT t.admin_locked OR NOT EXISTS(SELECT 1 FROM purchase_refund_states s WHERE s.order_item_id=t.order_item_id AND s.completed_refund_item_id IS NOT NULL)) THEN RAISE EXCEPTION 'Support refund closure mismatch';END IF;
IF NEW.type IN ('customer_replied','seller_replied','admin_replied') AND (NEW.previous_status NOT IN ('awaiting_seller','awaiting_customer') OR NEW.status<>CASE WHEN NEW.type='customer_replied' THEN 'awaiting_seller' ELSE 'awaiting_customer' END OR NOT templaara_support_purchase(t.customer_user_id,t.order_item_id,false)) THEN RAISE EXCEPTION 'Support reply requires active qualifying conversation';END IF;
IF NEW.type='resolved' AND (NEW.status<>'resolved' OR NEW.previous_status NOT IN ('awaiting_seller','awaiting_customer') OR (NEW.actor_id IS DISTINCT FROM t.seller_user_id AND NOT templaara_review_permission(NEW.actor_id,'support.manage'))) THEN RAISE EXCEPTION 'Invalid support resolution';END IF;
IF NEW.type='closed' AND (NEW.status<>'closed' OR (t.admin_locked AND (NEW.actor_id IS NULL OR NOT templaara_review_permission(NEW.actor_id,'support.manage') OR length(trim(NEW.reason))<5)) OR (NOT t.admin_locked AND (NEW.actor_id IS DISTINCT FROM t.customer_user_id OR NEW.previous_status<>'resolved'))) THEN RAISE EXCEPTION 'Invalid support closure';END IF;
IF NEW.type='reopened' AND (NEW.status<>'awaiting_seller' OR NEW.previous_status<>'resolved' OR NOT templaara_support_purchase(t.customer_user_id,t.order_item_id) OR (NEW.actor_id IS DISTINCT FROM t.customer_user_id AND NOT templaara_review_permission(NEW.actor_id,'support.manage'))) THEN RAISE EXCEPTION 'Invalid support reopen';END IF;
IF NEW.type IN ('escalated','deescalated') AND (length(trim(NEW.reason))<5 OR NEW.actor_id IS NULL OR (NEW.type='escalated')<>t.is_escalated OR (NEW.actor_id IS DISTINCT FROM t.seller_user_id AND NOT templaara_review_permission(NEW.actor_id,'support.manage'))) THEN RAISE EXCEPTION 'Invalid support escalation';END IF;
IF NEW.type IN ('priority_changed','escalated','deescalated','internal_note_added') AND NEW.previous_status<>NEW.status THEN RAISE EXCEPTION 'Administrative metadata cannot change lifecycle';END IF;
RETURN NEW;END;$$;
CREATE TRIGGER support_event_guard BEFORE INSERT OR UPDATE OR DELETE ON support_ticket_events FOR EACH ROW EXECUTE FUNCTION templaara_support_event_guard();
CREATE FUNCTION templaara_support_audit_complete() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE t support_tickets%ROWTYPE;rid bigint;BEGIN
IF TG_TABLE_NAME='support_tickets' THEN rid=NEW.id;ELSIF TG_TABLE_NAME='support_messages' THEN rid=NEW.ticket_id;ELSE SELECT ticket_id INTO rid FROM support_messages WHERE id=NEW.message_id;END IF;
SELECT * INTO t FROM support_tickets WHERE id=rid;
IF (SELECT count(*) FROM support_ticket_events WHERE ticket_id=rid)<>t.event_sequence OR NOT EXISTS(SELECT 1 FROM support_messages WHERE ticket_id=rid AND sequence=1 AND sender_user_id=t.customer_user_id AND NOT is_internal) OR t.last_message_at IS DISTINCT FROM (SELECT max(created_at) FROM support_messages WHERE ticket_id=rid AND NOT is_internal) THEN RAISE EXCEPTION 'Support requires complete initial message and audit/activity';END IF;
IF t.status<>'closed' AND EXISTS(SELECT 1 FROM purchase_refund_states s WHERE s.order_item_id=t.order_item_id AND s.completed_refund_item_id IS NOT NULL) THEN RAISE EXCEPTION 'Refund support closure required';END IF;
IF TG_TABLE_NAME='support_messages' THEN IF NOT EXISTS(SELECT 1 FROM support_ticket_events WHERE ticket_id=rid AND sequence=NEW.sequence AND actor_id=NEW.sender_user_id AND type=CASE WHEN NEW.sequence=1 THEN 'created' WHEN NEW.is_internal THEN 'internal_note_added' ELSE NEW.sender_type||'_replied' END) THEN RAISE EXCEPTION 'Support message requires matching event';END IF;END IF;
RETURN NEW;END;$$;
CREATE CONSTRAINT TRIGGER support_audit_complete AFTER INSERT OR UPDATE ON support_tickets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_support_audit_complete();
CREATE CONSTRAINT TRIGGER support_message_complete AFTER INSERT ON support_messages DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_support_audit_complete();
CREATE CONSTRAINT TRIGGER support_attachment_complete AFTER INSERT ON support_attachments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_support_audit_complete();
CREATE FUNCTION templaara_support_refund_closed() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF NEW.completed_refund_item_id IS NOT NULL AND EXISTS(SELECT 1 FROM support_tickets WHERE order_item_id=NEW.order_item_id AND status<>'closed') THEN RAISE EXCEPTION 'Completed item refund must close support';END IF;RETURN NEW;END;$$;
CREATE CONSTRAINT TRIGGER support_refund_closed AFTER INSERT OR UPDATE ON purchase_refund_states DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_support_refund_closed();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Support history requires a reviewed forward migration.');
    }
};
