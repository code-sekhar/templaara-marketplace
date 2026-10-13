<?php

namespace App\Listeners;

use App\Events\Commerce\OrderPaid;
use App\Services\Entitlements\EntitlementProvisioner;
use Illuminate\Support\Facades\Log;

class ProvisionPaidOrder
{
    public function __construct(private EntitlementProvisioner $provisioner) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->provisioner->provision($event->order);
        } catch (\Throwable $exception) {
            Log::error('Paid order software access requires reconciliation.', ['order_id' => $event->order->id, 'exception' => $exception::class]);
        }
    }
}
