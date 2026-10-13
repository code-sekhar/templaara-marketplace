<?php

namespace App\Services\Earnings;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class ReconcileEarnings
{
    /** @return array{orders:int,earnings:int,errors:int} */
    public function run(?string $number = null, bool $dryRun = false): array
    {
        $counts = ['orders' => 0, 'earnings' => 0, 'errors' => 0];
        $q = Order::where('status', 'completed')->where('payment_status', 'paid')->whereHas('items', fn ($q) => $q->where('seller_type', 'author')->whereDoesntHave('earning'));
        if ($number !== null) {
            $q->where('order_number', $number);
        }
        $q->chunkById(100, function ($rows) use (&$counts, $dryRun) {
            foreach ($rows as $order) {
                $counts['orders']++;
                if ($dryRun) {
                    $counts['earnings'] += $order->items()->where('seller_type', 'author')->whereDoesntHave('earning')->count();

                    continue;
                }try {
                    $counts['earnings'] += app(EarningProvisioner::class)->provision($order);
                } catch (\Throwable $ex) {
                    $counts['errors']++;
                    Log::error('Author earning reconciliation requires operational review.', ['order_id' => $order->id, 'exception' => $ex::class]);
                }
            }
        });

        return $counts;
    }
}
