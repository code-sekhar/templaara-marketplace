<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Commerce\OrderPaid;
use App\Events\Commerce\PaymentFailed;
use App\Events\Commerce\PaymentSucceeded;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\MarketplaceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentLifecycle
{
    public function __construct(private CartService $carts, private Checkout $checkout, private MarketplaceQuery $marketplace) {}

    public function process(VerifiedPayment $evidence): Order
    {
        $candidate = Payment::where('provider', $evidence->provider)->where('provider_payment_id', $evidence->reference)->with('order.user')->first();
        if (! $candidate) {
            $this->error('Unknown payment reference.');
        }

        return DB::transaction(function () use ($evidence, $candidate): Order {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$evidence->provider.':'.$evidence->eventId]);
            }
            $cart = $this->carts->locked($candidate->order->user);
            $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($payment->provider_order_id !== $evidence->orderReference || $payment->currency !== $evidence->currency
                || $order->currency->value !== $evidence->currency || Money::decimal($payment->amount) !== Money::decimal($evidence->amount)
                || Money::decimal($order->grand_total) !== Money::decimal($evidence->amount)) {
                $this->error('Payment amount, currency or order reference does not match.');
            }
            $existing = PaymentEvent::where('provider', $evidence->provider)->where('provider_event_id', $evidence->eventId)->first();
            if ($existing) {
                if ($existing->payment_id !== $payment->id || ! hash_equals($existing->payload_hash, $evidence->payloadHash)) {
                    $this->error('Payment event identity was already used with different contents.');
                }

                return $order;
            }
            $accepted = $payment->status === PaymentStatus::Pending && $order->status === OrderStatus::PendingPayment;
            (new PaymentEvent)->forceFill(['payment_id' => $payment->id, 'provider' => $evidence->provider, 'provider_event_id' => $evidence->eventId,
                'event_type' => $evidence->outcome, 'payload_hash' => $evidence->payloadHash, 'status' => $accepted ? 'processed' : 'ignored', 'processed_at' => now()])->save();
            if (! $accepted) {
                return $order;
            }
            if ($evidence->outcome === 'paid') {
                $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
                $order->forceFill(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now(),
                    'settled_payment_id' => $payment->id, 'invoice_number' => 'INV-'.now()->year.'-'.Str::ulid()])->save();
                foreach ($order->items as $item) {
                    $cart->items()->whereKey($item->source_cart_item_id)->where('terms_hash', $item->cart_terms_hash)->delete();
                }
                $cart->forceFill(['currency' => $cart->items()->exists() ? $cart->currency : null, 'checkout_key' => (string) Str::uuid()])->save();
                PaymentSucceeded::dispatch($payment);
                OrderPaid::dispatch($order);
            } else {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_code' => 'payment_failed', 'failure_message' => 'Payment was not completed. Please try again.'])->save();
                $order->forceFill(['status' => OrderStatus::PaymentFailed, 'payment_status' => PaymentStatus::Failed])->save();
                PaymentFailed::dispatch($payment);
            }

            return $order;
        }, 3);
    }

    public function retry(User $actor, Order $target): Order
    {
        return DB::transaction(function () use ($actor, $target): Order {
            $this->carts->locked($actor);
            $order = Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('retry', $order);
            if ($order->status === OrderStatus::PendingPayment) {
                return $order;
            }
            if ($order->status !== OrderStatus::PaymentFailed) {
                $this->error('Only unpaid failed orders may be retried.');
            }
            foreach ($order->items()->orderBy('product_id')->get() as $item) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if ($product) {
                    Category::whereKey($product->category_id)->lockForUpdate()->first();
                    $product = $this->marketplace->visible()->whereKey($product->id)->first();
                }
                $offer = $product?->offers()->whereKey($item->product_offer_id)->where('is_active', true)->lockForUpdate()->first();
                $release = $product ? $this->marketplace->publicRelease($product->releases()->getQuery())->reorder()->orderByDesc('released_at')->orderByDesc('id')->lockForUpdate()->first() : null;
                if (! $product || ! $offer || ! $release || ! hash_equals($item->cart_terms_hash, $this->carts->hash($product, $offer, $release))) {
                    $this->error('Software or package terms changed. Cancel this unpaid order and review a new checkout.');
                }
            }
            $this->checkout->attempt($order);
            $order->forceFill(['status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending])->save();

            return $order;
        }, 3);
    }

    public function cancel(User $actor, Order $target, bool $admin = false): Order
    {
        return DB::transaction(function () use ($actor, $target, $admin): Order {
            // Lock the owner's cart also for admin actions to maintain the global lock order.
            $cart = $this->carts->locked($target->user);
            $order = Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($admin ? 'manage' : 'cancel', $order);
            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }
            if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentFailed], true)) {
                $this->error('Paid orders cannot be cancelled. Refunds are not available in this phase.');
            }
            if ($order->payments()->where('status', 'pending')->whereIn('provider', ['stripe', 'razorpay'])->exists()) {
                $this->error('External payment is unresolved. Check payment status before cancelling.');
            }
            $order->payments()->where('status', 'pending')->update(['status' => 'cancelled']);
            $order->forceFill(['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Cancelled, 'cancelled_at' => now()])->save();
            if ($cart->checkout_key === $order->checkout_key) {
                $cart->forceFill(['checkout_key' => (string) Str::uuid()])->save();
            }

            return $order;
        }, 3);
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
