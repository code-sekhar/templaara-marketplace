<?php

namespace App\Services\Commerce\Gateways;

use App\Contracts\ExternalPaymentGateway;
use App\Models\Payment;
use App\Models\PaymentCheckout;

final class StripeGateway implements ExternalPaymentGateway
{
    public function available(): bool
    {
        $mode = config('commerce.stripe.mode');

        return config('commerce.stripe.enabled') === true && in_array($mode, ['test', 'live'], true)
            && (! app()->isProduction() || $mode === 'live')
            && str_starts_with((string) config('commerce.stripe.secret_key'), 'sk_'.$mode.'_')
            && str_starts_with((string) config('commerce.stripe.public_key'), 'pk_'.$mode.'_')
            && str_starts_with((string) config('commerce.stripe.webhook_secret'), 'whsec_');
    }

    public function create(Payment $payment): ProviderCheckout
    {
        $this->ready();
        $amount = MinorUnits::fromDecimal($payment->amount, $payment->currency);
        // Zero-price commercial checkout requires a separate future no-charge policy.
        if ($amount < ($payment->currency === 'GBP' ? 30 : 50)) {
            throw new ProviderUnavailable;
        }
        $url = route('commerce.payments.status', $payment->order);
        $data = ProviderHttp::send(ProviderHttp::stripe()->withHeaders(['Idempotency-Key' => 'templaara-'.$payment->idempotency_key]), 'post', '/checkout/sessions', [
            'mode' => 'payment', 'client_reference_id' => $payment->order->order_number,
            'metadata' => ['order_number' => $payment->order->order_number, 'attempt_key' => $payment->idempotency_key],
            'success_url' => $url.'?returned=success', 'cancel_url' => $url.'?returned=cancel',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($payment->currency), 'unit_amount' => $amount, 'product_data' => ['name' => 'Templaara software order']]]],
        ]);

        return $this->created($data, $payment);
    }

    public function recover(PaymentCheckout $checkout): ?ProviderCheckout
    {
        // Stripe may prune keys after 24h; never recreate beyond a conservative 23h boundary.
        if ($checkout->created_at->lt(now()->subHours(23))) {
            return null;
        }

        return $this->create($checkout->payment);
    }

    /** @param array<string,mixed> $data */
    private function created(array $data, Payment $payment): ProviderCheckout
    {
        $this->matches($data, $payment);
        $url = $data['url'] ?? null;
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') {
            throw new ProviderUnavailable;
        }

        return new ProviderCheckout(ProviderHttp::reference($data['id'] ?? null, 'cs_'), $url);
    }

    /** @param array<string,mixed> $data */
    private function matches(array $data, Payment $payment): void
    {
        if (($data['mode'] ?? null) !== 'payment' || ($data['livemode'] ?? null) !== (config('commerce.stripe.mode') === 'live')
            || ($data['metadata']['attempt_key'] ?? null) !== $payment->idempotency_key
            || ($data['metadata']['order_number'] ?? null) !== $payment->order->order_number
            || ($data['client_reference_id'] ?? null) !== $payment->order->order_number
            || MinorUnits::toDecimal($data['amount_total'] ?? null, strtoupper($data['currency'] ?? '')) !== $payment->amount) {
            throw new ProviderUnavailable;
        }
        if (strtoupper($data['currency'] ?? '') !== $payment->currency) {
            throw new ProviderUnavailable;
        }
    }

    public function fetch(PaymentCheckout $checkout): ProviderResult
    {
        $this->ready();
        $data = ProviderHttp::send(ProviderHttp::stripe(), 'get', '/checkout/sessions/'.$checkout->provider_order_id);
        $this->matches($data, $checkout->payment);

        $type = null;
        $intentState = null;
        if (($data['status'] ?? '') === 'complete' && ($data['payment_status'] ?? '') === 'unpaid' && isset($data['payment_intent'])) {
            $id = ProviderHttp::reference($data['payment_intent'], 'pi_');
            $intent = ProviderHttp::send(ProviderHttp::stripe(), 'get', '/payment_intents/'.$id);
            if (($intent['id'] ?? null) !== $id || strtoupper($intent['currency'] ?? '') !== $checkout->payment->currency
                || MinorUnits::toDecimal($intent['amount'] ?? null, strtoupper($intent['currency'] ?? '')) !== $checkout->payment->amount) {
                throw new ProviderUnavailable;
            }
            $intentState = $intent['status'] ?? null;
            if ($intentState === 'succeeded' && ($intent['amount_received'] ?? null) === $intent['amount']) {
                $data['payment_status'] = 'paid';
            } elseif ($intentState === 'canceled' || ($intentState === 'requires_payment_method' && is_array($intent['last_payment_error'] ?? null))) {
                $type = 'checkout.session.async_payment_failed';
            }
        }

        return $this->normalize($data, 'reconcile-'.hash('sha256', json_encode([$data['id'] ?? '', $data['status'] ?? '', $data['payment_status'] ?? '', $intentState], JSON_THROW_ON_ERROR)), type: $type);
    }

    public function webhook(string $body, string $signature, string $eventId = ''): ?ProviderResult
    {
        $this->ready();
        abort_if(strlen($body) > 65536 || strlen($signature) > 2048, 413);
        preg_match('/(?:^|,)t=(\d+)(?:,|$)/', $signature, $timestamp);
        preg_match_all('/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $signature, $signatures);
        $valid = false;
        if (isset($timestamp[1]) && abs(time() - (int) $timestamp[1]) <= 300) {
            $expected = hash_hmac('sha256', $timestamp[1].'.'.$body, config('commerce.stripe.webhook_secret'));
            foreach ($signatures[1] as $candidate) {
                $valid = $valid || hash_equals($expected, $candidate);
            }
        }
        abort_unless($valid, 401, 'Invalid payment signature.');
        $event = ProviderHttp::body($body);
        $id = ProviderHttp::reference($event['id'] ?? null, 'evt_');
        abort_unless(($event['livemode'] ?? null) === (config('commerce.stripe.mode') === 'live'), 400, 'Invalid payment mode.');
        if (! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            return null;
        }
        $data = $event['data']['object'] ?? null;
        abort_unless(is_array($data), 400, 'Malformed payment event.');

        return $this->normalize($data, $id, hash('sha256', $body), $event['type']);
    }

    /** @param array<string,mixed> $data */
    private function normalize(array $data, string $eventId, ?string $hash = null, ?string $type = null): ProviderResult
    {
        if (($data['mode'] ?? null) !== 'payment' || ($data['livemode'] ?? null) !== (config('commerce.stripe.mode') === 'live')) {
            throw new ProviderUnavailable;
        }
        if (($type === 'checkout.session.expired' && ($data['status'] ?? '') !== 'expired')
            || ($type === 'checkout.session.async_payment_failed' && ($data['payment_status'] ?? '') !== 'unpaid')
            || ($type === 'checkout.session.async_payment_succeeded' && (($data['status'] ?? '') !== 'complete' || ($data['payment_status'] ?? '') !== 'paid'))) {
            throw new ProviderUnavailable;
        }
        $state = ($data['payment_status'] ?? '') === 'paid' && ($data['status'] ?? '') === 'complete' ? 'paid'
            : ((($data['status'] ?? '') === 'expired' || $type === 'checkout.session.async_payment_failed') ? 'failed' : 'pending');

        return new ProviderResult('stripe', $eventId, ProviderHttp::reference($data['id'] ?? null, 'cs_'),
            isset($data['payment_intent']) ? ProviderHttp::reference($data['payment_intent'], 'pi_') : null, $state,
            MinorUnits::toDecimal($data['amount_total'] ?? null, strtoupper($data['currency'] ?? '')), strtoupper($data['currency'] ?? ''),
            $hash ?? hash('sha256', json_encode([$data['id'], $state, $data['amount_total'], $data['currency']], JSON_THROW_ON_ERROR)),
            $data['metadata']['attempt_key'] ?? null, $data['metadata']['order_number'] ?? null);
    }

    /** @return array<string,mixed> */
    public function browser(PaymentCheckout $checkout): array
    {
        return ['provider' => 'stripe', 'url' => $checkout->checkout_url];
    }

    private function ready(): void
    {
        if (! $this->available()) {
            throw new ProviderUnavailable;
        }
    }
}
