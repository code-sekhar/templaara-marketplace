<?php

use App\Models\Order;
use App\Models\Refund;
use App\Services\Authors\AuthorMedia;
use App\Services\Commerce\ExternalPayments;
use App\Services\Earnings\ReconcileEarnings;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Entitlements\ReconcileEntitlements;
use App\Services\Invoicing\InvoiceRecovery;
use App\Services\Notifications\NotificationRecovery;
use App\Services\Products\Files\FileRetention;
use App\Services\Refunds\ReconcileRefunds;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('products:prune-files', function (): void {
    $processed = 0;
    DB::table('product_file_cleanup')->where('available_at', '<=', now())->orderBy('id')->chunkById(100, function ($jobs) use (&$processed): void {
        foreach ($jobs as $job) {
            if (app(FileRetention::class)->cleanup($job->id)) {
                $processed++;
            }
        }
    });
    $this->info("Processed {$processed} product file cleanup records.");
})->purpose('Retry safe product file cleanup and remove abandoned staged uploads');
Schedule::command('products:prune-files')->hourly()->withoutOverlapping();

Artisan::command('commerce:reconcile-entitlements {--order= : Restrict to an order number} {--dry-run : Report missing access without writing}', function (): int {
    $number = $this->option('order');
    if ($number !== null && ! is_string($number)) {
        $this->error('Supply an order number string.');

        return 1;
    }
    if ($number && ! Order::where('order_number', $number)->exists()) {
        $this->error('Order not found.');

        return 1;
    }
    $counts = app(ReconcileEntitlements::class)->run($number ?: null, (bool) $this->option('dry-run'));
    $this->info(($this->option('dry-run') ? 'Dry run: ' : '').implode(', ', array_map(fn ($key, $value) => "{$key}: {$value}", array_keys($counts), $counts)));

    return $counts['errors'] ? 1 : 0;
})->purpose('Recover missing entitlements and base licenses for verified paid orders');
Schedule::command('commerce:reconcile-entitlements')->everyTenMinutes()->withoutOverlapping();

Artisan::command('commerce:reconcile-author-earnings {--order=} {--dry-run}', function (): int {
    $number = $this->option('order');
    if ($number !== null && ! is_string($number)) {
        $this->error('Supply an order number string.');

        return 1;
    }
    if ($number && ! Order::where('order_number', $number)->exists()) {
        $this->error('Order not found.');

        return 1;
    }
    $counts = app(ReconcileEarnings::class)->run($number ?: null, (bool) $this->option('dry-run'));
    $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

    return $counts['errors'] ? 1 : 0;
})->purpose('Recover author earnings from verified paid purchase snapshots');
Schedule::command('commerce:reconcile-author-earnings')->everyTenMinutes()->withoutOverlapping();
Artisan::command('commerce:release-author-earnings', function (): int {
    $counts = app(ReleaseEarnings::class)->run();
    $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

    return $counts['errors'] ? 1 : 0;
})->purpose('Move matured author earnings from pending to available');
Schedule::command('commerce:release-author-earnings')->everyTenMinutes()->withoutOverlapping();

Artisan::command('commerce:reconcile-refunds {--refund= : Restrict to a refund reference}', function (): int {
    $reference = $this->option('refund');
    if ($reference !== null && (! is_string($reference) || ! Refund::where('reference', $reference)->exists())) {
        $this->error('Refund reference not found.');

        return 1;
    }
    $counts = app(ReconcileRefunds::class)->run($reference);
    $this->info('Checked: '.$counts['checked'].', incomplete: '.$counts['errors']);

    return $counts['errors'] ? 1 : 0;
})->purpose('Inspect atomic completed refunds for incomplete internal effects without changing financial history');

Artisan::command('notifications:reconcile {--limit=100} {--retry= : Explicitly retry one failed/uncertain delivery UUID}', function (): int {
    $retry = $this->option('retry');
    if ($retry) {
        if (! Str::isUuid($retry)) {
            $this->error('Supply a delivery UUID.');

            return 1;
        }
        // Operator explicitly accepts possible SMTP acknowledgement ambiguity before retrying.
        $updated = DB::table('notification_deliveries')->where('id', $retry)->whereIn('email_status', ['failed', 'uncertain'])->update(['email_status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'last_error' => null, 'updated_at' => now()]);
        if (! $updated) {
            $this->error('No retryable delivery found.');

            return 1;
        }
    }
    $recovery = app(NotificationRecovery::class);
    $result = $recovery->run(null, (int) $this->option('limit'));
    try {
        $result['queued'] = $recovery->dispatchPending();
    } catch (Throwable $e) {
        $this->error('Email dispatch unavailable; durable work retained.');

        return 1;
    }
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['errors'] ? 1 : 0;
})->purpose('Recover notification delivery from committed domain histories without replaying financial workflows');
Schedule::command('notifications:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('payments:reconcile {--limit=100}', function (): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 500) {
        $this->error('Limit must be between 1 and 500.');

        return 1;
    }
    $counts = app(ExternalPayments::class)->reconcile($limit);
    $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

    return $counts['errors'] ? 1 : 0;
})->purpose('Recover unresolved external payment attempts through authoritative provider state');
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('invoices:reconcile {--limit=100}', function (): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 500) {
        $this->error('Limit must be between 1 and 500.');

        return 1;
    }
    $result = app(InvoiceRecovery::class)->run($limit);
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['errors'] ? 1 : 0;
});
Schedule::command('invoices:reconcile')->everyTenMinutes()->withoutOverlapping();

// A shared atomic-lock cache is required for multi-node scheduler leadership.
if (config('production.multi_node')) {
    foreach (Schedule::events() as $event) {
        $event->onOneServer();
    }
}

Artisan::command('authors:cleanup-media {--limit=100}', function (): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 500) {
        $this->error('Limit must be between 1 and 500.');

        return 1;
    }
    $this->info('Retired author images cleaned: '.app(AuthorMedia::class)->cleanup($limit));

    return 0;
});
$authorMediaCleanup = Schedule::command('authors:cleanup-media')->daily()->withoutOverlapping();
if (config('production.multi_node')) {
    $authorMediaCleanup->onOneServer();
}
