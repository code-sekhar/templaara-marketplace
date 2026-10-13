<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\Gateways\MinorUnits;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

final class ExternalPaymentFixtures
{
    public static function configure(): void
    {
        config()->set('commerce.gateway', 'external');
        config()->set('commerce.stripe', ['enabled' => true, 'mode' => 'test', 'public_key' => 'pk_test_fixture', 'secret_key' => 'sk_test_fixture', 'webhook_secret' => 'whsec_fixture']);
        config()->set('commerce.razorpay', ['enabled' => true, 'mode' => 'test', 'key_id' => 'rzp_test_fixture', 'key_secret' => 'fixture_key_secret', 'webhook_secret' => 'fixture_webhook_secret']);
        Http::preventStrayRequests();
        self::creation();
    }

    public static function fake(array|callable $responses = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    public static function creation(?callable $probe = null): void
    {
        self::fake(function ($request) use ($probe) {
            if ($request->method() === 'POST' && $probe) {
                $probe($request);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions')) {
                $payment = Payment::where('idempotency_key', $request['metadata']['attempt_key'])->firstOrFail();

                return Http::response([...self::stripe($payment), 'status' => 'open', 'payment_status' => 'unpaid']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/orders')) {
                $payment = Payment::where('idempotency_key', $request['receipt'])->firstOrFail();

                return Http::response(self::razorOrder($payment));
            }

            return Http::response([], 404);
        });
    }

    public static function order(string $provider = 'stripe', bool $prepare = true): Order
    {
        $product = EarningFixtures::product(price: '5000.00', currency: $provider === 'stripe' ? 'USD' : 'INR');
        $order = EarningFixtures::order($product, false);
        if ($prepare) {
            app(ExternalPayments::class)->prepare($order->payments()->firstOrFail()->checkout);
        }

        return $order->fresh();
    }

    public static function stripe(Payment $payment): array
    {
        return ['id' => 'cs_test_'.str_replace('-', '', $payment->idempotency_key), 'mode' => 'payment', 'livemode' => false,
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_'.str_replace('-', '', $payment->idempotency_key),
            'amount_total' => MinorUnits::fromDecimal($payment->amount, $payment->currency), 'currency' => strtolower($payment->currency),
            'client_reference_id' => $payment->order->order_number, 'metadata' => ['attempt_key' => $payment->idempotency_key, 'order_number' => $payment->order->order_number],
            'url' => 'https://checkout.stripe.com/c/pay/fixture'];
    }

    public static function razorOrder(Payment $payment): array
    {
        return ['id' => 'order_'.str_replace('-', '', $payment->idempotency_key), 'amount' => MinorUnits::fromDecimal($payment->amount, $payment->currency),
            'currency' => $payment->currency, 'receipt' => $payment->idempotency_key, 'partial_payment' => false, 'status' => 'created'];
    }

    public static function razor(Payment $payment): array
    {
        return ['id' => 'pay_'.str_replace('-', '', $payment->idempotency_key), 'order_id' => self::razorOrder($payment)['id'],
            'amount' => MinorUnits::fromDecimal($payment->amount, $payment->currency), 'currency' => $payment->currency, 'status' => 'captured', 'captured' => true];
    }

    public static function event(Payment $payment, array $changes = [], ?string $id = null, ?string $type = null): array
    {
        if ($payment->provider === 'stripe') {
            $body = json_encode(['id' => $id ?? 'evt_'.str_replace('-', '', $payment->idempotency_key), 'livemode' => false,
                'type' => $type ?? 'checkout.session.completed', 'data' => ['object' => array_replace_recursive(self::stripe($payment), $changes)]], JSON_THROW_ON_ERROR);

            return [$body, ['Stripe-Signature' => self::stripeSignature($body)]];
        }
        $body = json_encode(['event' => $type ?? 'payment.captured', 'payload' => ['payment' => ['entity' => array_replace(self::razor($payment), $changes)]]], JSON_THROW_ON_ERROR);

        return [$body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'fixture_webhook_secret'), 'X-Razorpay-Event-Id' => $id ?? 'evt_'.str_replace('-', '', $payment->idempotency_key)]];
    }

    public static function stripeSignature(string $body, ?int $at = null): string
    {
        $at ??= time();

        return 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$body, 'whsec_fixture');
    }

    public static function checkout(Order $order): PaymentCheckout
    {
        return $order->payments()->latest('id')->firstOrFail()->checkout;
    }
}
