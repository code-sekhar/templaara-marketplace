<?php

namespace App\Listeners;

use App\Services\Notifications\NotificationRecovery;
use Illuminate\Support\Facades\Log;

class DiscoverMarketplaceNotifications
{
    public function __construct(private NotificationRecovery $recovery) {}

    public function consume(object $event): void
    {
        // Events are committed wakeups only. Durable history, not event payload, determines identity/content.
        try {
            $family = match (true) {
                str_contains($event::class, '\\Refunds\\') => 'refund_events',str_contains($event::class, '\\Payouts\\') => 'payout_events',
                str_contains($event::class, '\\Support\\') => 'support_ticket_events',str_contains($event::class, '\\Reviews\\') => 'product_review_events',
                str_contains($event::class, '\\Authors\\') => 'author_application_events',str_contains($event::class, '\\Products\\') => 'product_lifecycle_events',
                str_contains($event::class, 'AuthorEarning') => 'author_earning_ledger_entries',default => 'orders',
            };
            $this->recovery->run($family);
        } catch (\Throwable $e) {
            Log::warning('Notification wakeup deferred to recovery.', ['exception' => $e::class]);
        }
    }
}
