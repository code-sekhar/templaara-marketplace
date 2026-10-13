<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->uuid('public_uuid')->nullable()->unique();
        });
        DB::table('products')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row->id)->update(['public_uuid' => (string) Str::uuid()]);
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN public_uuid SET NOT NULL');
        }
        Schema::table('product_offers', fn (Blueprint $t) => $t->unsignedInteger('activation_limit')->default(1));
        // Adding a default snapshots a documented legacy policy, without rewriting order identities.
        Schema::table('order_items', fn (Blueprint $t) => $t->unsignedInteger('activation_limit')->default(1));
        Schema::create('license_activations', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            foreach (['license', 'entitlement', 'product'] as $ref) {
                $t->foreignId($ref.'_id')->constrained()->restrictOnDelete();
            }
            $t->char('installation_id_hash', 64);
            $t->string('installation_label', 100)->nullable();
            $t->char('domain_hash', 64)->nullable();
            $t->string('domain_display', 253)->nullable();
            $t->string('status', 20);
            $t->timestampTz('activated_at');
            $t->timestampTz('last_validated_at')->nullable();
            $t->timestampTz('deactivated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['license_id', 'installation_id_hash']);
            $t->index(['license_id', 'status']);
        });
        Schema::create('license_activation_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('license_activation_id')->constrained()->restrictOnDelete();
            $t->string('action', 20);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE product_offers ADD CONSTRAINT offer_activation_limit CHECK(activation_limit BETWEEN 1 AND 1000);
ALTER TABLE order_items ADD CONSTRAINT item_activation_limit CHECK(activation_limit BETWEEN 1 AND 1000);
ALTER TABLE license_activations ADD CONSTRAINT activation_state CHECK ((status='active' AND deactivated_at IS NULL) OR (status='deactivated' AND deactivated_at IS NOT NULL));
ALTER TABLE license_activations ADD CONSTRAINT activation_domain CHECK ((domain_hash IS NULL) = (domain_display IS NULL));
CREATE FUNCTION templaara_activation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE lim integer; occupied integer;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Activation history cannot be deleted'; END IF;
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)-ARRAY['status','activated_at','last_validated_at','deactivated_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','activated_at','last_validated_at','deactivated_at','updated_at']) THEN RAISE EXCEPTION 'Activation identity is immutable'; END IF;
 PERFORM 1 FROM licenses WHERE id=NEW.license_id FOR UPDATE;
 SELECT i.activation_limit INTO lim FROM licenses l JOIN entitlements e ON e.id=l.entitlement_id JOIN order_items i ON i.id=e.order_item_id WHERE l.id=NEW.license_id AND l.entitlement_id=NEW.entitlement_id AND l.product_id=NEW.product_id AND e.product_id=NEW.product_id;
 IF lim IS NULL THEN RAISE EXCEPTION 'Activation purchase chain mismatch'; END IF;
 IF NEW.status='active' THEN
 SELECT count(*) INTO occupied FROM license_activations WHERE license_id=NEW.license_id AND status='active' AND id<>NEW.id;
 IF occupied>=lim THEN RAISE EXCEPTION 'Activation limit exceeded'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER activation_guard BEFORE INSERT OR UPDATE OR DELETE ON license_activations FOR EACH ROW EXECUTE FUNCTION templaara_activation_guard();
CREATE FUNCTION templaara_activation_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Activation events are immutable'; END; $$;
CREATE TRIGGER activation_event_guard BEFORE UPDATE OR DELETE ON license_activation_events FOR EACH ROW EXECUTE FUNCTION templaara_activation_event_guard();
CREATE FUNCTION templaara_product_uuid_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.public_uuid IS DISTINCT FROM OLD.public_uuid THEN RAISE EXCEPTION 'Public product identity is immutable'; END IF; RETURN NEW; END; $$;
CREATE TRIGGER product_uuid_guard BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION templaara_product_uuid_guard();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Commercial activation history requires a reviewed forward migration.');
    }
};
