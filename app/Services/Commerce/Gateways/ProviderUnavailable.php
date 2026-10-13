<?php

namespace App\Services\Commerce\Gateways;

final class ProviderUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment provider is temporarily unavailable.');
    }
}
