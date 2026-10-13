<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Services\Commerce\Gateways\ProviderCheckout;
use App\Services\Commerce\Gateways\ProviderResult;

interface ExternalPaymentGateway
{
    public function available(): bool;

    public function create(Payment $payment): ProviderCheckout;

    public function recover(PaymentCheckout $checkout): ?ProviderCheckout;

    public function fetch(PaymentCheckout $checkout): ?ProviderResult;

    public function webhook(string $body, string $signature, string $eventId = ''): ?ProviderResult;

    /** @return array<string, mixed> */
    public function browser(PaymentCheckout $checkout): array;
}
