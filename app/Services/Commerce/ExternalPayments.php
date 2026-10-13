<?php

namespace App\Services\Commerce;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Services\Commerce\Gateways\ProviderResult;
use App\Services\Commerce\Gateways\ProviderUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExternalPayments
{
    public function __construct(private PaymentGatewayManager $gateways, private PaymentLifecycle $lifecycle, private CartService $carts) {}

    /** Claim/commit before network I/O. A crash leaves an expiring lease; never repeat ambiguous Razorpay POST. */
    public function prepare(PaymentCheckout $target): PaymentCheckout
    {
        $claim = DB::transaction(function () use ($target): ?array {
            $checkout = PaymentCheckout::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($checkout->payment->status !== PaymentStatus::Pending || $checkout->state === 'ready' || $checkout->state === 'review'
                || ($checkout->claimed_at && $checkout->claimed_at->gt(now()->subMinutes(3)))) {
                return null;
            }
            $recover = $checkout->state !== 'reserved';
            $token = (string) Str::uuid();
            $checkout->forceFill(['state' => 'creating', 'claim_token' => $token, 'claimed_at' => now(), 'attempts' => $checkout->attempts + 1])->save();

            return [$checkout, $token, $recover];
        }, 3);
        if (! $claim) {
            return $target->fresh();
        }
        [$checkout, $token, $recover] = $claim;
        try {
            $gateway = $this->gateways->external($checkout->provider);
            $created = $recover ? $gateway->recover($checkout) : $gateway->create($checkout->payment);
            DB::transaction(function () use ($checkout, $token, $created): void {
                $row = PaymentCheckout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
                if ($row->claim_token !== $token) {
                    return;
                }
                $row->forceFill($created ? ['provider_order_id' => $created->reference, 'checkout_url' => $created->url, 'state' => 'ready', 'problem_code' => null]
                    : ['state' => 'review', 'problem_code' => 'creation_unresolved']);
                $row->forceFill(['claim_token' => null, 'claimed_at' => null, 'available_at' => now()->addMinute()])->save();
            }, 3);
        } catch (ProviderUnavailable|\InvalidArgumentException) {
            Log::warning('External checkout requires recovery.', ['provider' => $checkout->provider, 'attempt' => $checkout->payment->idempotency_key, 'code' => 'provider_unavailable']);
            PaymentCheckout::whereKey($checkout->id)->where('claim_token', $token)->update(['state' => 'uncertain', 'claim_token' => null, 'claimed_at' => null,
                'problem_code' => 'provider_unavailable', 'available_at' => now()->addMinutes(min(60, 2 ** min($checkout->attempts, 6)))->format('Y-m-d H:i:sP'), 'updated_at' => now()->format('Y-m-d H:i:sP')]);
        }

        return $target->fresh();
    }

    /** @return array<string,mixed>|null */
    public function browser(Order $order): ?array
    {
        $payment = $order->payments()->where('status', 'pending')->latest('id')->first();
        if (! $payment || $payment->provider === 'development') {
            return null;
        }
        $checkout = $payment->checkout;
        if (! $checkout || $checkout->state !== 'ready' || ! $this->gateways->external($payment->provider)->available()) {
            return null;
        }

        return $this->gateways->external($payment->provider)->browser($checkout);
    }

    public function settle(ProviderResult $result): void
    {
        $target = PaymentCheckout::where('provider', $result->provider)->where('provider_order_id', $result->objectId)->with('payment.order.user')->first();
        if (! $target) {
            throw new ProviderUnavailable;
        } // Creation/webhook race: retry after durable binding.
        DB::transaction(function () use ($result, $target): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$result->provider.':'.$result->eventId]);
            }
            $this->carts->locked($target->payment->order->user);
            $order = Order::whereKey($target->payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($target->payment_id)->lockForUpdate()->firstOrFail();
            $checkout = PaymentCheckout::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($payment->provider !== $result->provider || $payment->amount !== $result->amount || $payment->currency !== $result->currency
                || $order->grand_total !== $result->amount || $order->currency->value !== $result->currency
                || ($result->provider === 'stripe' && ($result->attemptKey !== $payment->idempotency_key || $result->orderNumber !== $order->order_number))) {
                Log::warning('External payment evidence requires investigation.', ['provider' => $payment->provider, 'attempt' => $payment->idempotency_key, 'code' => 'evidence_mismatch']);
                $checkout->forceFill(['problem_code' => 'evidence_mismatch', 'state' => 'review', 'reconciled_at' => now()])->save();

                return;
            }
            if ($result->state === 'pending') {
                return;
            }
            if (! in_array($result->state, ['paid', 'failed'], true) || ($result->state === 'paid' && ! $result->paymentId)) {
                throw new ProviderUnavailable;
            }
            if ($checkout->provider_payment_id !== null && $checkout->provider_payment_id !== $result->paymentId) {
                $checkout->forceFill(['problem_code' => 'additional_payment', 'state' => 'review', 'reconciled_at' => now()])->save();

                return;
            }
            $this->lifecycle->process(new VerifiedPayment($payment->provider, $result->eventId, $payment->provider_payment_id,
                $payment->provider_order_id, $result->amount, $result->currency, $result->state, $result->hash));
            $payment->refresh();
            $late = $result->state === 'paid' && ($payment->status !== PaymentStatus::Paid || $order->fresh()->getAttribute('settled_payment_id') !== $payment->id);
            $checkout->forceFill(['provider_payment_id' => $result->state === 'paid' ? $result->paymentId : $checkout->provider_payment_id,
                'state' => $late ? 'review' : ($payment->status === PaymentStatus::Paid ? 'settled' : 'failed'),
                'problem_code' => $late ? 'late_success' : null, 'reconciled_at' => now()])->save();
        }, 3);
    }

    /** @return array{checked:int,errors:int} */
    public function reconcile(int $limit = 100): array
    {
        $counts = ['checked' => 0, 'errors' => 0];
        $ids = PaymentCheckout::whereIn('state', ['reserved', 'creating', 'uncertain', 'ready'])->where('available_at', '<=', now()->format('Y-m-d H:i:sP'))
            ->orderBy('id')->limit(max(1, min(500, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $counts['checked']++;
            $checkout = PaymentCheckout::whereKey($id)->firstOrFail();
            try {
                if (! $checkout->provider_order_id) {
                    $checkout = $this->prepare($checkout);
                }
                if ($checkout->provider_order_id) {
                    $result = $this->gateways->external($checkout->provider)->fetch($checkout);
                    if ($result) {
                        $this->settle($result);
                    }
                }
                PaymentCheckout::whereKey($id)->whereIn('state', ['ready', 'uncertain'])->update(['reconciled_at' => now()->format('Y-m-d H:i:sP'), 'available_at' => now()->addMinutes(5)->format('Y-m-d H:i:sP'), 'updated_at' => now()->format('Y-m-d H:i:sP')]);
            } catch (ProviderUnavailable|\InvalidArgumentException|ValidationException) {
                Log::warning('External payment reconciliation deferred.', ['provider' => $checkout->provider, 'attempt' => $checkout->payment->idempotency_key, 'code' => 'reconcile_unavailable']);
                $counts['errors']++;
                PaymentCheckout::whereKey($id)->whereNotIn('state', ['settled', 'failed', 'review'])->update(['problem_code' => 'reconcile_unavailable', 'reconciled_at' => now()->format('Y-m-d H:i:sP'), 'available_at' => now()->addMinutes(10)->format('Y-m-d H:i:sP'), 'updated_at' => now()->format('Y-m-d H:i:sP')]);
            }
        }

        return $counts;
    }
}
