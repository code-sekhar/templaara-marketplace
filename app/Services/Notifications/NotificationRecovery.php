<?php

namespace App\Services\Notifications;

use App\Jobs\DeliverNotificationEmail;
use App\Models\User;
use App\Notifications\MarketplaceNotification;
use App\Notifications\NotificationAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Existing append-only committed histories are the durable source outbox. No cursor can skip a late commit. */
class NotificationRecovery
{
    public function __construct(private NotificationFacts $facts, private NotificationPreferences $preferences, private NotificationContent $content) {}

    /** @return array{observed:int,errors:int} */
    public function run(?string $only = null, int $limit = 100): array
    {
        $result = ['observed' => 0, 'errors' => 0];
        $epoch = config('notifications.started_at') ?? DB::table('notification_epoch')->where('id', 1)->value('started_at');
        foreach ($this->facts->sources() as $source => $time) {
            if ($only && $only !== $source) {
                continue;
            }
            $rows = DB::table($source)->whereNotNull($time)->where($time, '>=', $epoch)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notification_observations')->where('source', $source)->whereColumn('source_id', $source.'.id'))
                ->orderBy('id')->limit(min(1000, max(1, $limit)))->get();
            foreach ($rows as $row) {
                try {
                    $facts = $this->facts->project($source, $row);
                    if ($facts === null) {
                        continue;
                    }
                    DB::transaction(function () use ($source, $row, $facts): void {
                        foreach ($facts as $f) {
                            $this->record($source, $row->id, $f);
                        }
                        DB::table('notification_observations')->insertOrIgnore(['source' => $source, 'source_id' => $row->id, 'observed_at' => now()]);
                    }, 3);
                    $result['observed']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    Log::warning('Notification discovery requires recovery.', ['source' => $source, 'source_id' => $row->id, 'exception' => $e::class]);
                }
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $fact */
    private function record(string $source, int $sourceId, array $fact): void
    {
        $user = User::whereKey($fact['user_id'])->lockForUpdate()->first();
        if (! $user) {
            return;
        }
        $type = $fact['type'];
        if (! NotificationAction::safe($fact['action_path'])) {
            throw new \LogicException('Unsafe notification action.');
        }
        $existing = DB::table('notification_deliveries')->where(['source' => $source, 'source_id' => $sourceId, 'notification_type' => $type->value, 'user_id' => $user->id])->exists();
        if ($existing) {
            return;
        }
        $channels = $this->preferences->channels($user, $type);
        $id = (string) Str::uuid();
        $payload = ['type' => $type->value, 'category' => $type->group(), ...$this->content->text($type), 'action_label' => 'View details', 'action_path' => $fact['action_path'], 'occurred_at' => CarbonImmutable::parse($fact['occurred_at'])->toIso8601String(), 'reference' => $fact['reference']];
        DB::table('notification_deliveries')->insert(['id' => $id, 'user_id' => $user->id, 'source' => $source, 'source_id' => $sourceId, 'notification_type' => $type->value, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'in_app_enabled' => $channels['in_app'], 'email_enabled' => $channels['email'], 'email_status' => $channels['email'] ? 'pending' : 'skipped', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($channels['in_app']) {
            $user->notifyNow(new MarketplaceNotification($id, $payload), ['database']);
        }
    }

    /** Queue submission is recoverable even if the process dies before dispatch. */
    public function dispatchPending(int $limit = 100): int
    {
        $connection = (string) config('notifications.connection');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new \LogicException('Marketplace email requires an asynchronous queue connection.');
        }
        $rows = DB::table('notification_deliveries')->where('email_status', 'pending')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($rows as $id) {
            $reserved = DB::table('notification_deliveries')->where('id', $id)->where('email_status', 'pending')->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))->update(['queued_at' => now(), 'updated_at' => now()]);
            if (! $reserved) {
                continue;
            }
            DeliverNotificationEmail::dispatch($id)->onConnection($connection)->onQueue(config('notifications.queue'))->afterCommit();
        }
        // A killed worker may have handed mail to the transport. Never blindly resend that ambiguous attempt.
        DB::table('notification_deliveries')->where('email_status', 'sending')->where('claimed_at', '<', now()->subMinutes(5))->update(['email_status' => 'uncertain', 'last_error' => 'worker_acknowledgement_missing', 'updated_at' => now()]);

        return $rows->count();
    }
}
