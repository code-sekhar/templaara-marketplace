<?php

namespace App\Listeners;

use App\Events\Commerce\OrderPaid;
use App\Services\Earnings\EarningProvisioner;
use Illuminate\Support\Facades\Log;

class ProvisionAuthorEarnings
{
    public function __construct(private EarningProvisioner $provisioner) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->provisioner->provision($event->order);
        } catch (\Throwable $ex) {
            Log::error('Paid order author earnings require reconciliation.', ['order_id' => $event->order->id, 'exception' => $ex::class]);
        }
    }
}
