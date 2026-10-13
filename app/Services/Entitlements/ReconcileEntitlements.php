<?php

namespace App\Services\Entitlements;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class ReconcileEntitlements
{
    public function __construct(private EntitlementProvisioner $provisioner) {}

    /** @return array{orders:int,entitlements:int,licenses:int,errors:int} */
    public function run(?string $number = null, bool $dryRun = false): array
    {
        $counts = ['orders' => 0, 'entitlements' => 0, 'licenses' => 0, 'errors' => 0];
        $query = Order::where('status', 'completed')->where('payment_status', 'paid')->whereHas('items', fn ($q) => $q->whereDoesntHave('entitlement')->orWhereHas('entitlement', fn ($e) => $e->whereDoesntHave('license')));
        if ($number !== null) {
            $query->where('order_number', $number);
        }
        $query->chunkById(100, function ($orders) use (&$counts, $dryRun): void {
            foreach ($orders as $order) {
                $counts['orders']++;
                $missing = $order->items()->whereDoesntHave('entitlement')->count();
                $missingLicenses = $order->items()->whereHas('entitlement', fn ($q) => $q->whereDoesntHave('license'))->count();
                if ($dryRun) {
                    $counts['entitlements'] += $missing;
                    $counts['licenses'] += $missing + $missingLicenses;

                    continue;
                }
                try {
                    $created = $this->provisioner->provision($order);
                    $counts['entitlements'] += $created['entitlements'];
                    $counts['licenses'] += $created['licenses'];
                } catch (\Throwable $exception) {
                    $counts['errors']++;
                    Log::error('Entitlement reconciliation requires operational review.', ['order_id' => $order->id, 'exception' => $exception::class]);
                }
            }
        });

        return $counts;
    }
}
