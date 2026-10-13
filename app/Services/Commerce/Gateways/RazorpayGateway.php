<?php

namespace App\Services\Commerce\Gateways;

use App\Contracts\ExternalPaymentGateway;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Services\Settings\MarketplaceSettings;

final class RazorpayGateway implements ExternalPaymentGateway
{
    public function available(): bool
    {
        $mode = config('commerce.razorpay.mode');

        return config('commerce.razorpay.enabled') === true && in_array($mode, ['test', 'live'], true)
            && (! app()->isProduction() || $mode === 'live') && filled(config('commerce.razorpay.key_secret')) && filled(config('commerce.razorpay.webhook_secret'))
            && str_starts_with((string) config('commerce.razorpay.key_id'), 'rzp_'.$mode.'_');
    }

    public function create(Payment $payment): ProviderCheckout
    {
        $this->ready();
        if ($payment->currency !== 'INR' || MinorUnits::fromDecimal($payment->amount, $payment->currency) < 100) {
            throw new ProviderUnavailable;
        }
        $data = ProviderHttp::send(ProviderHttp::razorpay(), 'post', '/orders', ['amount' => MinorUnits::fromDecimal($payment->amount, $payment->currency),
            'currency' => $payment->currency, 'receipt' => $payment->idempotency_key, 'partial_payment' => false,
            'notes' => ['order_number' => $payment->order->order_number, 'attempt_key' => $payment->idempotency_key]]);

        return $this->created($data, $payment);
    }

    /** @param array<string,mixed> $data */
    private function created(array $data, Payment $payment): ProviderCheckout
    {
        if (($data['receipt'] ?? null) !== $payment->idempotency_key || ($data['currency'] ?? null) !== $payment->currency
            || MinorUnits::toDecimal($data['amount'] ?? null, $data['currency']) !== $payment->amount
            || ($data['partial_payment'] ?? false) !== false) {
            throw new ProviderUnavailable;
        }

        return new ProviderCheckout(ProviderHttp::reference($data['id'] ?? null, 'order_'));
    }

    public function recover(PaymentCheckout $checkout): ?ProviderCheckout
    {
        $this->ready();
        $data = ProviderHttp::send(ProviderHttp::razorpay(), 'get', '/orders', ['receipt' => $checkout->payment->idempotency_key, 'count' => 2]);
        $items = $data['items'] ?? null;
        if (! is_array($items) || count($items) !== 1) {
            return null;
        }

        return $this->created($items[0], $checkout->payment);
    }

    public function fetch(PaymentCheckout $checkout): ?ProviderResult
    {
        $this->ready();
        $order = ProviderHttp::send(ProviderHttp::razorpay(), 'get', '/orders/'.$checkout->provider_order_id);
        $this->created($order, $checkout->payment);
        if (($order['id'] ?? null) !== $checkout->provider_order_id) {
            throw new ProviderUnavailable;
        }
        $data = ProviderHttp::send(ProviderHttp::razorpay(), 'get', '/orders/'.$checkout->provider_order_id.'/payments');
        $items = $data['items'] ?? null;
        if (! is_array($items) || count($items) > 100) {
            throw new ProviderUnavailable;
        }
        // Captured wins over earlier failures; authorized is not paid.
        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'captured') {
                return $this->normalize($item, 'reconcile-'.$item['id'].'-captured');
            }
        }
        if (count($items) === 1 && ($items[0]['status'] ?? '') === 'failed') {
            return $this->normalize($items[0], 'reconcile-'.$items[0]['id'].'-failed');
        }

        return null;
    }

    public function webhook(string $body, string $signature, string $eventId = ''): ?ProviderResult
    {
        $this->ready();
        abort_if(strlen($body) > 65536, 413);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $signature) && hash_equals(hash_hmac('sha256', $body, config('commerce.razorpay.webhook_secret')), $signature), 401, 'Invalid payment signature.');
        $event = ProviderHttp::body($body);
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $eventId) === 1, 400, 'Missing payment event identity.');
        if (! in_array($event['event'] ?? '', ['payment.captured', 'payment.failed', 'order.paid'], true)) {
            return null;
        }
        $data = $event['payload']['payment']['entity'] ?? null;
        abort_unless(is_array($data), 400, 'Malformed payment event.');
        if (($event['event'] === 'payment.failed' && ($data['status'] ?? '') !== 'failed')
            || ($event['event'] !== 'payment.failed' && ($data['status'] ?? '') !== 'captured')) {
            throw new ProviderUnavailable;
        }

        return $this->normalize($data, $eventId, hash('sha256', $body));
    }

    public function callback(PaymentCheckout $checkout, string $paymentId, string $orderId, string $signature): ProviderResult
    {
        $this->ready();
        abort_unless($orderId === $checkout->provider_order_id && preg_match('/^[a-f0-9]{64}$/D', $signature)
            && hash_equals(hash_hmac('sha256', $checkout->provider_order_id.'|'.$paymentId, config('commerce.razorpay.key_secret')), $signature), 401, 'Invalid payment signature.');
        ProviderHttp::reference($paymentId, 'pay_');
        $data = ProviderHttp::send(ProviderHttp::razorpay(), 'get', '/payments/'.$paymentId);
        if (($data['id'] ?? null) !== $paymentId || ($data['order_id'] ?? null) !== $checkout->provider_order_id) {
            throw new ProviderUnavailable;
        }

        return $this->normalize($data, 'callback-'.$paymentId.'-'.($data['status'] ?? ''));
    }

    /** @param array<string,mixed> $data */
    private function normalize(array $data, string $eventId, ?string $hash = null): ProviderResult
    {
        $state = ($data['status'] ?? '') === 'captured' && ($data['captured'] ?? false) === true ? 'paid' : (($data['status'] ?? '') === 'failed' ? 'failed' : 'pending');
        $id = ProviderHttp::reference($data['id'] ?? null, 'pay_');

        return new ProviderResult('razorpay', $eventId, ProviderHttp::reference($data['order_id'] ?? null, 'order_'), $id, $state,
            MinorUnits::toDecimal($data['amount'] ?? null, $data['currency'] ?? ''), $data['currency'] ?? '',
            $hash ?? hash('sha256', json_encode([$id, $state, $data['amount'], $data['currency'], $data['order_id']], JSON_THROW_ON_ERROR)));
    }

    /** @return array<string,mixed> */
    public function browser(PaymentCheckout $checkout): array
    {
        return ['provider' => 'razorpay', 'key' => config('commerce.razorpay.key_id'), 'order_id' => $checkout->provider_order_id,
            'amount' => MinorUnits::fromDecimal($checkout->payment->amount, $checkout->payment->currency), 'currency' => $checkout->payment->currency,
            'name' => app(MarketplaceSettings::class)->identity()['marketplace_name']];
    }

    private function ready(): void
    {
        if (! $this->available()) {
            throw new ProviderUnavailable;
        }
    }
}
