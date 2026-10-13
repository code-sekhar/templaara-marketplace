<?php

namespace App\Services\Commerce;

final readonly class VerifiedPayment
{
    public function __construct(public string $provider, public string $eventId, public string $reference, public string $orderReference,
        public string $amount, public string $currency, public string $outcome, public string $payloadHash) {}
}
