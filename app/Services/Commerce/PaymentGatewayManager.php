<?php

namespace App\Services\Commerce;

use App\Contracts\ExternalPaymentGateway;
use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Services\Commerce\Gateways\MinorUnits;
use App\Services\Commerce\Gateways\RazorpayGateway;
use App\Services\Commerce\Gateways\StripeGateway;

final class PaymentGatewayManager implements PaymentGateway
{
    public function available(): bool
    {
        if (config('commerce.gateway') === 'development') {
            return app(DevelopmentGateway::class)->available();
        }
        if (config('commerce.gateway') !== 'external') {
            return false;
        }
        foreach (['INR', 'USD', 'EUR', 'GBP'] as $currency) {
            if ($this->availableFor($currency)) {
                return true;
            }
        }

        return false;
    }

    public function availableFor(string $currency): bool
    {
        if (config('commerce.gateway') === 'development') {
            return app(DevelopmentGateway::class)->available();
        }
        $name = config('commerce.providers.'.$currency);

        return config('commerce.gateway') === 'external' && in_array($name, ['stripe', 'razorpay'], true)
            && ($name !== 'razorpay' || $currency === 'INR') && $this->external($name)->available();
    }

    public function external(string $provider): ExternalPaymentGateway
    {
        return match ($provider) {
            'stripe' => app(StripeGateway::class), 'razorpay' => app(RazorpayGateway::class), default => throw new \InvalidArgumentException('Unsupported payment provider.')
        };
    }

    /** Reserves immutable internal identity only. External creation occurs after commercial commit. @return array{provider:string,provider_order_id:string,provider_payment_id:string} */
    public function createIntent(Order $order, string $key): array
    {
        abort_unless($this->availableFor($order->currency->value), 503, 'Checkout is temporarily unavailable.');
        if (config('commerce.gateway') === 'development') {
            return app(DevelopmentGateway::class)->createIntent($order, $key);
        }

        $minimum = config('commerce.providers.'.$order->currency->value) === 'razorpay' ? 100 : ($order->currency->value === 'GBP' ? 30 : 50);
        abort_if(MinorUnits::fromDecimal($order->grand_total, $order->currency->value) < $minimum, 503, 'Order amount is below the payment provider minimum.');

        return ['provider' => config('commerce.providers.'.$order->currency->value), 'provider_order_id' => $order->order_number, 'provider_payment_id' => 'attempt-'.$key];
    }

    public function verifyWebhook(string $payload, string $signature): VerifiedPayment
    {
        // Original contract remains exclusively for the domain-separated development simulator.
        return app(DevelopmentGateway::class)->verifyWebhook($payload, $signature);
    }
}
