<?php

use App\Enums\MarketplaceNotificationType;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_epoch', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->timestampTz('started_at');
        });
        DB::table('notification_epoch')->insert(['id' => 1, 'started_at' => now()]);
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('notifiable_type');
            $t->foreignId('notifiable_id')->constrained('users')->cascadeOnDelete();
            $t->jsonb('data');
            $t->timestampTz('read_at')->nullable();
            $t->timestampsTz();
            $t->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at']);
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('group', 30);
            $t->boolean('in_app_enabled')->default(true);
            $t->boolean('email_enabled')->default(true);
            $t->timestampsTz();
            $t->unique(['user_id', 'group']);
        });
        Schema::create('notification_observations', function (Blueprint $t) {
            $t->string('source', 40);
            $t->unsignedBigInteger('source_id');
            $t->timestampTz('observed_at');
            $t->primary(['source', 'source_id']);
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('source', 40);
            $t->unsignedBigInteger('source_id');
            $t->string('notification_type', 50);
            $t->jsonb('payload');
            $t->boolean('in_app_enabled');
            $t->boolean('email_enabled');
            $t->string('email_status', 20);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('available_at');
            $t->timestampTz('queued_at')->nullable();
            $t->timestampTz('claimed_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->uuid('claim_token')->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestampsTz();
            $t->unique(['source', 'source_id', 'notification_type', 'user_id'], 'notification_logical_identity');
            $t->index(['email_status', 'available_at']);
            $t->index(['user_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            $types = implode(',', array_map(fn ($x) => "'".$x->value."'", MarketplaceNotificationType::cases()));
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_controlled_type CHECK (notification_type IN ($types)), ADD CONSTRAINT notification_delivery_state CHECK (email_status IN ('pending','sending','sent','skipped','failed','uncertain') AND attempts BETWEEN 0 AND 5 AND (email_status != 'sent' OR sent_at IS NOT NULL) AND (email_status != 'sending' OR (claim_token IS NOT NULL AND claimed_at IS NOT NULL)))");
            DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preference_group CHECK (\"group\" IN ('orders','refunds','author_workflow','product_updates','earnings','payouts','reviews','support'))");
            $keys = "ARRAY['type','category','title','message','action_label','action_path','occurred_at','reference']";
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notification_safe_payload CHECK (jsonb_typeof(data)='object' AND data-$keys='{}'::jsonb AND data->>'type' IN ($types) AND jsonb_exists_all(data,ARRAY['type','title','message','action_path','occurred_at']))");
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_safe_delivery CHECK (jsonb_typeof(payload)='object' AND payload-$keys='{}'::jsonb AND payload->>'type'=notification_type AND jsonb_exists_all(payload,ARRAY['type','title','message','action_path','occurred_at'])), ADD CONSTRAINT notification_known_source CHECK (source IN ('orders','refund_events','payout_events','author_application_events','product_lifecycle_events','author_earning_ledger_entries','product_review_events','support_ticket_events'))");
            $user = User::class;
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notification_user_type CHECK (notifiable_type = '$user')");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_notification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Notification history retained'; END IF;
 IF TG_TABLE_NAME='notifications' THEN
 IF (NEW.id IS DISTINCT FROM OLD.id OR NEW.type IS DISTINCT FROM OLD.type OR NEW.notifiable_id IS DISTINCT FROM OLD.notifiable_id OR NEW.notifiable_type IS DISTINCT FROM OLD.notifiable_type OR NEW.data IS DISTINCT FROM OLD.data OR NEW.created_at IS DISTINCT FROM OLD.created_at OR (OLD.read_at IS NOT NULL AND NEW.read_at IS DISTINCT FROM OLD.read_at)) THEN RAISE EXCEPTION 'Notification identity immutable'; END IF;
 END IF;
 IF TG_TABLE_NAME='notification_deliveries' THEN
 IF (NEW.id IS DISTINCT FROM OLD.id OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.source IS DISTINCT FROM OLD.source OR NEW.source_id IS DISTINCT FROM OLD.source_id OR NEW.notification_type IS DISTINCT FROM OLD.notification_type OR NEW.payload IS DISTINCT FROM OLD.payload OR NEW.in_app_enabled IS DISTINCT FROM OLD.in_app_enabled OR NEW.email_enabled IS DISTINCT FROM OLD.email_enabled OR NEW.created_at IS DISTINCT FROM OLD.created_at OR (OLD.email_status='sent' AND NEW IS DISTINCT FROM OLD)) THEN RAISE EXCEPTION 'Delivery identity immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER notification_identity BEFORE UPDATE ON notifications FOR EACH ROW EXECUTE FUNCTION templaara_notification_guard();
CREATE TRIGGER notification_delivery_identity BEFORE UPDATE ON notification_deliveries FOR EACH ROW EXECUTE FUNCTION templaara_notification_guard();
SQL);
        }
    }

    public function down(): void
    {
        throw new LogicException('Notification history rollback requires a deliberate retention policy.');
    }
};
