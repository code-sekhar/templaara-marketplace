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
CREATE OR REPLACE FUNCTION templaara_payout_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p payout_requests%ROWTYPE; prior text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Payout audit append-only'; END IF;
 SELECT * INTO p FROM payout_requests WHERE id=NEW.payout_request_id FOR UPDATE;
 SELECT status INTO prior FROM payout_events WHERE payout_request_id=p.id ORDER BY id DESC LIMIT 1;
 IF (prior IS NULL AND NEW.status<>'requested') OR
    (prior IS NOT NULL AND NOT (
      (prior='requested' AND NEW.status IN ('approved','cancelled')) OR
      (prior='approved' AND NEW.status IN ('processing','cancelled')) OR
      (prior='processing' AND NEW.status IN ('paid','failed'))
    )) THEN RAISE EXCEPTION 'Payout audit requires every lifecycle step'; END IF;
 IF NEW.status<>p.status OR NEW.previous_status IS DISTINCT FROM prior OR
    NEW.actor_id IS DISTINCT FROM (CASE NEW.status
      WHEN 'requested' THEN p.author_user_id WHEN 'approved' THEN p.reviewed_by
      WHEN 'processing' THEN p.processing_by WHEN 'paid' THEN p.paid_by
      WHEN 'failed' THEN p.failed_by WHEN 'cancelled' THEN p.cancelled_by END) OR
    NEW.occurred_at IS DISTINCT FROM (CASE NEW.status
      WHEN 'requested' THEN p.requested_at WHEN 'approved' THEN p.reviewed_at
      WHEN 'processing' THEN p.processing_at WHEN 'paid' THEN p.paid_at
      WHEN 'failed' THEN p.failed_at WHEN 'cancelled' THEN p.cancelled_at END)
 THEN RAISE EXCEPTION 'Payout audit actor/state mismatch'; END IF;
 RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Financial audit protection requires reviewed forward migrations.');
    }
};
