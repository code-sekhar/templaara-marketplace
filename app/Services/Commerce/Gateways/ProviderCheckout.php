<?php

namespace App\Services\Commerce\Gateways;

final readonly class ProviderCheckout
{
    public function __construct(public string $reference, public ?string $url = null) {}
}
