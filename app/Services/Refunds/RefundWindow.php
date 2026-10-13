<?php

namespace App\Services\Refunds;

use App\Models\Order;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Validation\ValidationException;

class RefundWindow
{
    public function days(): int
    {
        return app(MarketplaceSettings::class)->refundDays();
    }

    public function forOrder(Order $order): int
    {
        return $order->getAttribute('refund_window_days') ?? $this->legacyDays();
    }

    public function legacyDays(): int
    {
        $v = config('refunds.window_days');
        if (($v === null || $v === '') && app()->environment('local', 'testing')) {
            $v = config('refunds.development_window_days');
        }
        if (! is_scalar($v) || ! preg_match('/^\d{1,4}$/', (string) $v) || (int) $v > 3650) {
            throw ValidationException::withMessages(['refund' => 'Refund policy is unavailable. Contact Templaara.']);
        }

        return (int) $v;
    }
}
