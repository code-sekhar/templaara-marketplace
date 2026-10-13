<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            foreach (['user', 'order', 'order_item', 'product', 'product_offer', 'product_release', 'product_artifact'] as $reference) {
                $t->foreignId($reference.'_id')->constrained()->restrictOnDelete();
            }
            $t->unique('order_item_id');
            $t->string('status', 20);
            $t->timestampTz('granted_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'granted_at', 'id']);
            $t->index(['order_id']);
            $t->index(['product_artifact_id']);
            $t->index(['product_id']);
            $t->index(['product_offer_id']);
            $t->index(['product_release_id']);
        });
        Schema::create('licenses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entitlement_id')->unique()->constrained()->restrictOnDelete();
            foreach (['user', 'order_item', 'product', 'product_release'] as $reference) {
                $t->foreignId($reference.'_id')->constrained()->restrictOnDelete();
            }
            $t->text('license_secret');
            $t->char('license_key_hash', 64)->unique();
            $t->char('license_key_last4', 4);
            $t->string('status', 20);
            $t->timestampTz('issued_at');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
            foreach (['user_id', 'order_item_id', 'product_id', 'product_release_id'] as $field) {
                $t->index($field);
            }
        });
        Schema::create('download_accesses', function (Blueprint $t): void {
            $t->id();
            foreach (['entitlement', 'user', 'order_item', 'product_release', 'product_artifact'] as $reference) {
                $t->foreignId($reference.'_id')->constrained()->restrictOnDelete();
            }
            $t->string('delivery', 20);
            $t->timestampTz('accessed_at');
            $t->timestampTz('url_expires_at')->nullable();
            $t->timestamps();
            $t->index(['entitlement_id', 'accessed_at']);
            foreach (['user_id', 'order_item_id', 'product_release_id', 'product_artifact_id'] as $field) {
                $t->index($field);
            }
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['entitlements', 'licenses'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_state CHECK (status IN ('active','revoked','expired') AND ((status='revoked')=(revoked_at IS NOT NULL)))");
        }
        DB::statement("ALTER TABLE licenses ADD CONSTRAINT license_hash CHECK (license_key_hash ~ '^[a-f0-9]{64}$' AND license_key_last4 ~ '^[A-F0-9]{4}$')");
        DB::statement("ALTER TABLE download_accesses ADD CONSTRAINT download_delivery CHECK (delivery IN ('stream','temporary_url') AND ((delivery='temporary_url')=(url_expires_at IS NOT NULL)))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_entitlement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Purchase access history is retained'; END IF;
    IF TG_OP='UPDATE' THEN
        IF TG_TABLE_NAME='download_accesses' OR (to_jsonb(NEW)-ARRAY['status','revoked_at','expires_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','revoked_at','expires_at','updated_at']) THEN
            RAISE EXCEPTION 'Purchase access identity is immutable';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME='entitlements' THEN
        IF NOT EXISTS (SELECT 1 FROM order_items i JOIN orders o ON o.id=i.order_id JOIN payments p ON p.id=o.settled_payment_id
          JOIN product_artifacts a ON a.id=NEW.product_artifact_id JOIN product_releases r ON r.id=i.product_release_id
          WHERE i.id=NEW.order_item_id AND o.id=NEW.order_id AND o.user_id=NEW.user_id AND o.status='completed' AND o.payment_status='paid'
          AND p.order_id=o.id AND p.status='paid' AND p.amount=o.grand_total AND p.currency=o.currency
          AND i.product_id=NEW.product_id AND i.product_offer_id=NEW.product_offer_id AND i.product_release_id=NEW.product_release_id
          AND r.product_id=i.product_id AND r.version=i.version AND a.product_release_id=i.product_release_id AND a.type='source_package') THEN
          RAISE EXCEPTION 'Entitlement requires matching paid order and exact purchased package';
        END IF;
    ELSIF TG_TABLE_NAME='licenses' THEN
        IF NOT EXISTS (SELECT 1 FROM entitlements e WHERE e.id=NEW.entitlement_id AND e.user_id=NEW.user_id AND e.order_item_id=NEW.order_item_id AND e.product_id=NEW.product_id AND e.product_release_id=NEW.product_release_id) THEN
          RAISE EXCEPTION 'License ownership or commercial references mismatch';
        END IF;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM entitlements e JOIN orders o ON o.id=e.order_id WHERE e.id=NEW.entitlement_id AND e.user_id=NEW.user_id
          AND e.order_item_id=NEW.order_item_id AND e.product_release_id=NEW.product_release_id AND e.product_artifact_id=NEW.product_artifact_id
          AND e.status='active' AND e.revoked_at IS NULL AND (e.expires_at IS NULL OR e.expires_at>NEW.accessed_at) AND o.status='completed' AND o.payment_status='paid') THEN
          RAISE EXCEPTION 'Download access requires matching active entitlement';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER entitlement_integrity BEFORE INSERT OR UPDATE OR DELETE ON entitlements FOR EACH ROW EXECUTE FUNCTION templaara_entitlement_guard();
CREATE TRIGGER license_integrity BEFORE INSERT OR UPDATE OR DELETE ON licenses FOR EACH ROW EXECUTE FUNCTION templaara_entitlement_guard();
CREATE TRIGGER download_access_integrity BEFORE INSERT OR UPDATE OR DELETE ON download_accesses FOR EACH ROW EXECUTE FUNCTION templaara_entitlement_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retained entitlements and licenses require forward migrations.');
    }
};
