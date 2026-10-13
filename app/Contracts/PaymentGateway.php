<?php

namespace App\Contracts;

use App\Models\Order;
use App\Services\Commerce\VerifiedPayment;

interface PaymentGateway
{
    public function available(): bool;

    /** @return array{provider: string, provider_order_id: string, provider_payment_id: string} */
    public function createIntent(Order $order, string $key): array;

    public function verifyWebhook(string $payload, string $signature): VerifiedPayment;
}
