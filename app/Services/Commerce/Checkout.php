<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Commerce\OrderPlaced;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\User;
use App\Services\Earnings\CommissionPolicies;
use App\Services\Invoicing\CheckoutTax;
use App\Services\Refunds\RefundWindow;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function __construct(private CartService $carts, private PaymentGateway $gateway) {}

    /** @param array<string,mixed> $billing */
    public function place(User $user, string $key, array $billing): Order
    {
        return DB::transaction(function () use ($user, $key, $billing): Order {
            app(MarketplaceSettings::class)->lock();
            $cart = $this->carts->locked($user);
            $existing = Order::where('user_id', $user->id)->where('checkout_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            if ($cart->checkout_key !== $key) {
                $this->error('Your cart changed. Review checkout again.');
            }
            $quote = $this->carts->resolve($cart, true);
            if (! $quote['valid']) {
                $this->error('Review and confirm all cart issues before placing your order.');
            }
            abort_unless($this->gateway instanceof PaymentGatewayManager ? $this->gateway->availableFor($cart->currency->value) : $this->gateway->available(), 503, 'Checkout is temporarily unavailable.');
            $tax = app(CheckoutTax::class)->quote(array_values(array_map(fn ($row) => $row['offer']->price, $quote['items'])), $billing);
            if ($tax['policy']['enabled'] && ! hash_equals(app(CheckoutTax::class)->hash($tax, $billing, $key), (string) ($billing['tax_quote_hash'] ?? ''))) {
                throw ValidationException::withMessages(['tax' => 'Tax or billing terms changed. Confirm the current tax quote before placing the order.']);
            }
            unset($billing['tax_quote_hash']);
            $order = new Order;
            $order->forceFill([...$billing, 'order_number' => 'TMP-'.now()->year.'-'.Str::ulid(), 'user_id' => $user->id, 'checkout_key' => $key,
                'status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'currency' => $cart->currency,
                'subtotal' => $quote['total'], 'discount_total' => '0.00', 'tax_total' => $tax['tax_total'], 'grand_total' => $tax['grand_total'], 'tax_snapshot' => $tax['policy']['enabled'] ? Arr::except($tax, ['lines']) : null, 'placed_at' => now(), 'refund_window_days' => app(RefundWindow::class)->days()])->save();
            foreach ($quote['items'] as $position => $row) {
                $product = $row['product'];
                $offer = $row['offer'];
                $release = $row['release'];
                (new OrderItem)->forceFill(['order_id' => $order->id, 'product_id' => $product->id, 'product_offer_id' => $offer->id, 'product_release_id' => $release->id,
                    ...app(CommissionPolicies::class)->snapshot($product, $offer->price, $order->getAttribute('placed_at')),
                    'source_cart_item_id' => $row['item']->id, 'cart_terms_hash' => $row['item']->terms_hash,
                    'product_name' => $product->name, 'product_slug' => $product->slug, 'offer_name' => $offer->name, 'offer_description' => $offer->description, 'license_type' => $offer->license_type->value,
                    'tax_snapshot' => $tax['policy']['enabled'] ? $tax['lines'][$position] : null, 'unit_price' => $offer->price, 'quantity' => 1, 'line_total' => $offer->price, 'currency' => $product->currency->value,
                    'support_period_days' => $offer->support_period_days, 'updates_period_days' => $offer->updates_period_days, 'version' => $release->version,
                    'activation_limit' => $offer->getAttribute('activation_limit') ?? 1, 'origin' => $product->origin->value, 'seller_name' => $product->owner->name ?? 'Templaara'])->save();
            }
            $this->attempt($order);
            OrderPlaced::dispatch($order);

            return $order;
        }, 3);
    }

    /** Caller holds User, Cart and Order locks. */
    public function attempt(Order $order): Payment
    {
        $key = (string) Str::uuid();
        $intent = $this->gateway->createIntent($order, $key);
        $payment = new Payment;
        $payment->forceFill([...$intent, 'order_id' => $order->id, 'idempotency_key' => $key, 'amount' => $order->grand_total, 'currency' => $order->currency->value, 'status' => PaymentStatus::Pending])->save();

        if (in_array($payment->provider, ['stripe', 'razorpay'], true)) {
            (new PaymentCheckout)->forceFill(['payment_id' => $payment->id, 'provider' => $payment->provider, 'state' => 'reserved', 'available_at' => now()])->save();
        }

        return $payment;
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['cart' => $message]);
    }
}
