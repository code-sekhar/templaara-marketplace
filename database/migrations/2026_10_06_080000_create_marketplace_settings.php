<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('group', 30);
            $t->string('key', 60);
            $t->jsonb('value');
            $t->string('value_type', 20);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['group', 'key']);
        });
        Schema::create('marketplace_setting_events', function (Blueprint $t): void {
            $t->id();
            $t->string('group', 30);
            $t->string('key', 60);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->jsonb('old_value')->nullable();
            $t->jsonb('new_value')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['group', 'key', 'id']);
        });
        Schema::table('orders', fn (Blueprint $t) => $t->unsignedSmallInteger('refund_window_days')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE marketplace_settings ADD CONSTRAINT settings_version_positive CHECK(version>0), ADD CONSTRAINT settings_known_group CHECK(\"group\" IN ('general','commerce','authors','payouts','refunds','products','support','notifications','reporting')), ADD CONSTRAINT settings_known_type CHECK(value_type IN ('string','email','integer','decimal','list','timezone','boolean'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT order_refund_window_bound CHECK(refund_window_days BETWEEN 0 AND 3650)');
            DB::unprepared(<<<'SQL'
CREATE FUNCTION marketplace_setting_audit_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Settings audit is immutable and retained'; END $$;
CREATE TRIGGER settings_audit_retained BEFORE UPDATE OR DELETE ON marketplace_setting_events FOR EACH ROW EXECUTE FUNCTION marketplace_setting_audit_guard();
CREATE FUNCTION marketplace_setting_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF TG_OP='UPDATE' AND (NEW.version <> OLD.version+1 OR NEW."group" IS DISTINCT FROM OLD."group" OR NEW.key IS DISTINCT FROM OLD.key) THEN RAISE EXCEPTION 'Settings identity/version is invalid'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER settings_version_guard BEFORE UPDATE ON marketplace_settings FOR EACH ROW EXECUTE FUNCTION marketplace_setting_version_guard();
SQL);
        }
    }

    public function down(): void
    {
        throw new LogicException('Settings history and purchase policy snapshots require forward migrations.');
    }
};
