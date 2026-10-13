<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\MarketplaceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliverNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new \LogicException('Email delivery cannot run inside a database transaction.');
        }
        $token = (string) Str::uuid();
        $row = DB::transaction(function () use ($token) {
            $r = DB::table('notification_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (! $r || $r->email_status !== 'pending' || $r->attempts >= 5 || CarbonImmutable::parse($r->available_at)->isFuture()) {
                return null;
            }
            DB::table('notification_deliveries')->where('id', $r->id)->update(['email_status' => 'sending', 'attempts' => $r->attempts + 1, 'claim_token' => $token, 'claimed_at' => now(), 'updated_at' => now()]);

            return $r;
        }, 3);
        if (! $row) {
            return;
        }
        try {
            $u = User::whereKey((int) $row->user_id)->first();
            if (! $u) {
                return;
            }
            $u->notifyNow(new MarketplaceNotification($row->id, json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)), ['mail']);
            DB::table('notification_deliveries')->where('id', $row->id)->where('claim_token', $token)->where('email_status', 'sending')->update(['email_status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $attempt = (int) $row->attempts + 1;
            DB::table('notification_deliveries')->where('id', $row->id)->where('claim_token', $token)->update(['email_status' => $attempt >= 5 ? 'failed' : 'pending', 'available_at' => now()->addSeconds($this->backoff[min($attempt - 1, 3)]), 'last_error' => 'mail_transport_failed', 'updated_at' => now()]);
            if (app()->isProduction()) {
                throw new \RuntimeException('Notification mail transport unavailable.');
            }
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->where('email_status', 'pending')->update(['email_status' => 'failed', 'last_error' => 'queue_retry_exhausted', 'updated_at' => now()]);
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->where('email_status', 'sending')->update(['email_status' => 'uncertain', 'last_error' => 'worker_acknowledgement_missing', 'updated_at' => now()]);
    }
}
