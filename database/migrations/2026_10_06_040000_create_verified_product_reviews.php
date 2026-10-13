<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('verified_order_item_id')->constrained('order_items')->restrictOnDelete();
            $t->smallInteger('rating');
            $t->string('title', 120)->nullable();
            $t->text('body');
            $t->string('status', 20);
            $t->boolean('is_verified');
            $t->timestampTz('published_at');
            $t->timestampTz('edited_at')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
            $t->timestampTz('hidden_at')->nullable();
            $t->unsignedInteger('event_sequence')->default(1);
            $t->timestampsTz();
            $t->unique(['customer_user_id', 'product_id']);
            $t->index(['product_id', 'status', 'is_verified', 'withdrawn_at', 'id']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('product_review_responses', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('review_id')->unique()->constrained('product_reviews')->restrictOnDelete();
            $t->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->unsignedInteger('review_sequence');
            $t->timestampTz('published_at');
            $t->timestampTz('edited_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('product_review_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_id')->constrained('product_reviews')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('type', 40);
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at');
            $t->unique(['review_id', 'sequence']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_review_purchase(customer bigint, product bigint, item bigint DEFAULT NULL) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id JOIN payments p ON p.id=o.settled_payment_id
 JOIN entitlements e ON e.order_item_id=i.id JOIN licenses l ON l.entitlement_id=e.id
 JOIN product_artifacts a ON a.id=e.product_artifact_id JOIN product_releases r ON r.id=a.product_release_id JOIN products pr ON pr.id=i.product_id
 WHERE o.user_id=customer AND i.product_id=product AND (item IS NULL OR i.id=item)
 AND o.status='completed' AND o.payment_status='paid' AND o.paid_at IS NOT NULL AND p.status='paid' AND p.order_id=o.id AND p.amount=o.grand_total AND p.currency=o.currency
 AND e.status='active' AND e.revoked_at IS NULL AND (e.expires_at IS NULL OR e.expires_at>now()) AND l.status='active' AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at>now())
 AND e.user_id=o.user_id AND e.order_id=o.id AND e.product_id=i.product_id AND e.product_offer_id=i.product_offer_id AND e.product_release_id=i.product_release_id
 AND l.user_id=o.user_id AND l.order_item_id=i.id AND l.product_id=i.product_id AND l.product_release_id=i.product_release_id
 AND a.type='source_package' AND a.product_release_id=i.product_release_id AND r.product_id=i.product_id AND r.version=i.version
 AND (pr.owner_id IS NULL OR pr.owner_id<>customer)
 AND NOT EXISTS(SELECT 1 FROM purchase_refund_states s WHERE s.order_item_id=i.id AND s.completed_refund_item_id IS NOT NULL)
 AND NOT EXISTS(SELECT 1 FROM model_has_roles m JOIN roles role ON role.id=m.role_id WHERE m.model_id=customer AND m.model_type='App\Models\User' AND role.name='admin' AND role.guard_name='web' AND pr.origin='templaara'));
$$;
ALTER TABLE product_reviews ADD CONSTRAINT review_values CHECK(rating BETWEEN 1 AND 5 AND length(trim(body)) BETWEEN 20 AND 5000 AND status IN ('published','hidden') AND ((status='hidden')=(hidden_at IS NOT NULL)) AND event_sequence>0 AND (edited_at IS NULL OR edited_at>=published_at) AND (withdrawn_at IS NULL OR withdrawn_at>=published_at) AND (hidden_at IS NULL OR hidden_at>=published_at));
ALTER TABLE product_review_responses ADD CONSTRAINT review_response_values CHECK(length(trim(body)) BETWEEN 2 AND 3000 AND (edited_at IS NULL OR edited_at>=published_at));
ALTER TABLE product_review_events ADD CONSTRAINT review_event_values CHECK(sequence>0 AND type IN ('created','edited','withdrawn','restored_by_customer','hidden_by_admin','restored_by_admin','verification_lost','verification_restored','response_created','response_edited') AND (type<>'hidden_by_admin' OR length(trim(reason))>=5));
CREATE FUNCTION templaara_review_permission(actor bigint, capability text) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS(SELECT 1 FROM permissions p WHERE p.name=capability AND p.guard_name='web' AND (EXISTS(SELECT 1 FROM model_has_permissions m WHERE m.permission_id=p.id AND m.model_id=actor AND m.model_type='App\Models\User') OR EXISTS(SELECT 1 FROM role_has_permissions g JOIN model_has_roles m ON m.role_id=g.role_id WHERE g.permission_id=p.id AND m.model_id=actor AND m.model_type='App\Models\User')));$$;
CREATE FUNCTION templaara_review_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Review history retained'; END IF;
 PERFORM id FROM users WHERE id=NEW.customer_user_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=NEW.verified_order_item_id AND i.product_id=NEW.product_id AND o.user_id=NEW.customer_user_id) THEN RAISE EXCEPTION 'Review purchase identity mismatch'; END IF;
 IF NEW.is_verified AND NOT templaara_review_purchase(NEW.customer_user_id,NEW.product_id,NEW.verified_order_item_id) THEN RAISE EXCEPTION 'Review requires qualifying paid purchase'; END IF;
 IF TG_OP='INSERT' THEN
  IF NOT NEW.is_verified OR NEW.status<>'published' OR NEW.withdrawn_at IS NOT NULL OR NEW.event_sequence<>1 OR NEW.edited_at IS NOT NULL THEN RAISE EXCEPTION 'New review must be verified published'; END IF;
 ELSE
  IF (NEW.id,NEW.public_uuid,NEW.product_id,NEW.customer_user_id,NEW.published_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.public_uuid,OLD.product_id,OLD.customer_user_id,OLD.published_at,OLD.created_at) OR NEW.event_sequence<>OLD.event_sequence+1 THEN RAISE EXCEPTION 'Review identity/audit immutable'; END IF;
  IF (NEW.rating,NEW.title,NEW.body) IS DISTINCT FROM (OLD.rating,OLD.title,OLD.body) AND (NOT NEW.is_verified OR NEW.withdrawn_at IS NOT NULL OR NEW.edited_at IS NULL) THEN RAISE EXCEPTION 'Only qualified current reviews editable'; END IF;
 END IF; RETURN NEW; END; $$;
CREATE TRIGGER review_guard BEFORE INSERT OR UPDATE OR DELETE ON product_reviews FOR EACH ROW EXECUTE FUNCTION templaara_review_guard();
CREATE FUNCTION templaara_review_response_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE r product_reviews%ROWTYPE; pr products%ROWTYPE; BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Response history retained'; END IF;
 SELECT * INTO r FROM product_reviews WHERE id=NEW.review_id;
 PERFORM id FROM users WHERE id=r.customer_user_id FOR UPDATE;
 SELECT * INTO pr FROM products WHERE id=r.product_id;
 IF NEW.review_sequence<>r.event_sequence+1 THEN RAISE EXCEPTION 'Response requires new audit sequence'; END IF;
 IF r.status<>'published' OR r.withdrawn_at IS NOT NULL OR NOT r.is_verified OR NOT templaara_review_purchase(r.customer_user_id,r.product_id,r.verified_order_item_id) THEN RAISE EXCEPTION 'Response requires visible qualified review'; END IF;
 IF NOT ((pr.origin='author' AND pr.owner_id=NEW.author_user_id) OR (pr.origin='templaara' AND EXISTS(SELECT 1 FROM model_has_roles m JOIN roles role ON role.id=m.role_id WHERE m.model_id=NEW.author_user_id AND m.model_type='App\Models\User' AND role.name='admin' AND role.guard_name='web') AND templaara_review_permission(NEW.author_user_id,'reviews.manage'))) THEN RAISE EXCEPTION 'Response seller mismatch'; END IF;
 IF TG_OP='UPDATE' AND (NEW.id,NEW.public_uuid,NEW.review_id,NEW.author_user_id,NEW.published_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.public_uuid,OLD.review_id,OLD.author_user_id,OLD.published_at,OLD.created_at) THEN RAISE EXCEPTION 'Response identity immutable'; END IF;
 RETURN NEW;END;$$;
CREATE TRIGGER review_response_guard BEFORE INSERT OR UPDATE OR DELETE ON product_review_responses FOR EACH ROW EXECUTE FUNCTION templaara_review_response_guard();
CREATE FUNCTION templaara_review_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE r product_reviews%ROWTYPE; BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Review audit append only'; END IF;
 SELECT * INTO r FROM product_reviews WHERE id=NEW.review_id;
 IF NEW.sequence<>r.event_sequence OR NEW.occurred_at<r.published_at THEN RAISE EXCEPTION 'Review audit mismatch'; END IF;
 IF NEW.type IN ('created','edited','withdrawn','restored_by_customer') AND NEW.actor_id IS DISTINCT FROM r.customer_user_id THEN RAISE EXCEPTION 'Review actor mismatch'; END IF;
 IF NEW.type IN ('hidden_by_admin','restored_by_admin') AND (NEW.actor_id IS NULL OR NOT templaara_review_permission(NEW.actor_id,'reviews.manage')) THEN RAISE EXCEPTION 'Review moderation requires permission'; END IF;
 IF NEW.type IN ('hidden_by_admin','restored_by_admin','response_created','response_edited') AND NEW.actor_id IS NULL THEN RAISE EXCEPTION 'Review action requires actor'; END IF;
 IF (NEW.sequence=1)<>(NEW.type='created') OR (NEW.type='withdrawn' AND r.withdrawn_at IS NULL) OR (NEW.type='hidden_by_admin' AND r.status<>'hidden') OR (NEW.type='restored_by_admin' AND r.status<>'published') OR (NEW.type='verification_lost' AND r.is_verified) OR (NEW.type='verification_restored' AND NOT r.is_verified) THEN RAISE EXCEPTION 'Review event state mismatch'; END IF;
 RETURN NEW;END;$$;
CREATE TRIGGER review_event_guard BEFORE INSERT OR UPDATE OR DELETE ON product_review_events FOR EACH ROW EXECUTE FUNCTION templaara_review_event_guard();
CREATE FUNCTION templaara_review_audit_complete() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE rid bigint; seq integer; BEGIN
 IF TG_TABLE_NAME='product_reviews' THEN rid=NEW.id; ELSE rid=NEW.review_id; END IF;
 SELECT event_sequence INTO seq FROM product_reviews WHERE id=rid;
 IF EXISTS(SELECT 1 FROM product_reviews r WHERE r.id=rid AND r.is_verified AND NOT templaara_review_purchase(r.customer_user_id,r.product_id,r.verified_order_item_id)) THEN RAISE EXCEPTION 'Verified review requires current qualifying purchase at commit'; END IF;
 IF TG_TABLE_NAME='product_review_responses' THEN IF NOT EXISTS(SELECT 1 FROM product_review_events WHERE review_id=rid AND sequence=NEW.review_sequence AND actor_id=NEW.author_user_id AND type=CASE WHEN TG_OP='INSERT' THEN 'response_created' ELSE 'response_edited' END) THEN RAISE EXCEPTION 'Response requires matching audit'; END IF; END IF;
 IF (SELECT count(*) FROM product_review_events WHERE review_id=rid)<>seq THEN RAISE EXCEPTION 'Review requires complete audit'; END IF;
 RETURN NEW;END;$$;
CREATE CONSTRAINT TRIGGER review_audit_complete AFTER INSERT OR UPDATE ON product_reviews DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_review_audit_complete();
CREATE CONSTRAINT TRIGGER response_audit_complete AFTER INSERT OR UPDATE ON product_review_responses DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_review_audit_complete();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Review history requires a reviewed forward migration.');
    }
};
