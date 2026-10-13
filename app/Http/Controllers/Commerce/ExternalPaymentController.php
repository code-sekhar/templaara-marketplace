<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\Gateways\ProviderUnavailable;
use App\Services\Commerce\Gateways\RazorpayGateway;
use App\Services\Commerce\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ExternalPaymentController extends Controller
{
    public function webhook(Request $request, string $provider, PaymentGatewayManager $gateways, ExternalPayments $payments): JsonResponse
    {
        abort_if((int) $request->header('Content-Length', '0') > 65536 || strlen($request->getContent()) > 65536, 413);
        try {
            $result = $gateways->external($provider)->webhook($request->getContent(), $request->header($provider === 'stripe' ? 'Stripe-Signature' : 'X-Razorpay-Signature', ''), $request->header('X-Razorpay-Event-Id', ''));
            if ($result) {
                $payments->settle($result);
            }
        } catch (ProviderUnavailable|\InvalidArgumentException|ValidationException) {
            return response()->json(['received' => false, 'code' => 'PAYMENT_RETRY_REQUIRED'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['received' => true])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, Order $order, ExternalPayments $payments): Response
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        return Inertia::render('commerce/PaymentStatus', ['order' => ['order_number' => $order->order_number, 'status' => $order->status->value,
            'payment_status' => $order->payment_status->value, 'grand_total' => $order->grand_total, 'currency' => $order->currency->value],
            'handoff' => $payments->browser($order), 'canStart' => $order->status->value === 'pending_payment']);
    }

    public function start(Request $request, Order $order, ExternalPayments $payments): RedirectResponse
    {
        Gate::authorize('retry', $order);
        $request->validate(array_fill_keys(['amount', 'currency', 'provider', 'status', 'user_id', 'order_id'], ['prohibited']));
        $checkout = $order->payments()->where('status', 'pending')->latest('id')->first()?->checkout;
        if ($checkout) {
            $payments->prepare($checkout);
        }

        return to_route('commerce.payments.status', $order)->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request, Order $order, RazorpayGateway $gateway, ExternalPayments $payments): RedirectResponse
    {
        Gate::authorize('retry', $order);
        $data = $request->validate(['razorpay_payment_id' => ['required', 'string', 'regex:/^pay_[A-Za-z0-9_]{1,90}$/D'],
            'razorpay_order_id' => ['required', 'string', 'regex:/^order_[A-Za-z0-9_]{1,90}$/D'], 'razorpay_signature' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ...array_fill_keys(['amount', 'currency', 'provider', 'status', 'user_id', 'order_id'], ['prohibited'])]);
        $checkout = $order->payments()->where('provider', 'razorpay')->whereHas('checkout', fn ($q) => $q->where('provider_order_id', $data['razorpay_order_id']))->first()?->checkout;
        abort_unless($checkout !== null, 404);
        try {
            $result = $gateway->callback($checkout, $data['razorpay_payment_id'], $data['razorpay_order_id'], $data['razorpay_signature']);
            $payments->settle($result);
        } catch (ProviderUnavailable|\InvalidArgumentException) {
            throw ValidationException::withMessages(['payment' => 'Payment confirmation is pending. Check your order again shortly.']);
        }

        return to_route('commerce.payments.status', $order)->header('Cache-Control', 'no-store');
    }
}
