<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DevelopmentGateway implements PaymentGateway
{
    public function available(): bool
    {
        return app()->environment(['local', 'testing']) && config('commerce.gateway') === 'development' && config('commerce.development_enabled') === true && filled(config('app.key'));
    }

    public function createIntent(Order $order, string $key): array
    {
        abort_unless($this->available(), 503, 'Checkout is temporarily unavailable.');

        return ['provider' => 'development', 'provider_order_id' => $order->order_number, 'provider_payment_id' => 'dev-'.$key];
    }

    public function verifyWebhook(string $payload, string $signature): VerifiedPayment
    {
        abort_unless($this->available(), 404);
        abort_if(strlen($payload) > 65536, 413);
        abort_unless(hash_equals($this->signature($payload), $signature), 401, 'Invalid payment signature.');
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Malformed payment event.');
        }
        abort_unless(is_array($data), 400, 'Malformed payment event.');
        $valid = Validator::make($data, [
            'event_id' => ['required', 'string', 'max:100'], 'reference' => ['required', 'string', 'max:100'], 'order_reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'], 'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'outcome' => ['required', Rule::in(['paid', 'failed'])],
        ])->validate();

        return new VerifiedPayment('development', $valid['event_id'], $valid['reference'], $valid['order_reference'], Money::decimal($valid['amount']), $valid['currency'], $valid['outcome'], hash('sha256', $payload));
    }

    public function signature(string $payload): string
    {
        abort_unless($this->available(), 404);

        return hash_hmac('sha256', 'templaara-development-webhook:'.$payload, config('app.key'));
    }

    public function simulate(Payment $payment, string $outcome): VerifiedPayment
    {
        abort_unless($this->available(), 404);
        // Server builds and signs the evidence; browser supplies only the local simulator outcome.
        $payload = json_encode(['event_id' => 'simulation-'.$payment->idempotency_key.'-'.$outcome, 'reference' => $payment->provider_payment_id,
            'order_reference' => $payment->provider_order_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'outcome' => $outcome], JSON_THROW_ON_ERROR);

        return $this->verifyWebhook($payload, $this->signature($payload));
    }
}
