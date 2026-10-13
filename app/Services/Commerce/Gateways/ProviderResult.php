<?php

namespace App\Services\Commerce\Gateways;

final readonly class ProviderResult
{
    public function __construct(public string $provider, public string $eventId, public string $objectId, public ?string $paymentId,
        public string $state, public string $amount, public string $currency, public string $hash,
        public ?string $attemptKey = null, public ?string $orderNumber = null) {}
}
