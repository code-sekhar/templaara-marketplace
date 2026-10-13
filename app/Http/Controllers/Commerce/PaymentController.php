<?php

namespace App\Http\Controllers\Commerce;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Commerce\DevelopmentGateway;
use App\Services\Commerce\PaymentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function simulate(Request $request, string $key, DevelopmentGateway $gateway, PaymentLifecycle $lifecycle): RedirectResponse
    {
        $payment = Payment::where('idempotency_key', $key)->with('order')->firstOrFail();
        Gate::authorize('retry', $payment->order);
        $data = $request->validate(['outcome' => ['required', Rule::in(['paid', 'failed'])], ...array_fill_keys(['amount', 'currency', 'status', 'provider_payment_id', 'order_id', 'user_id'], ['prohibited'])]);
        $order = $lifecycle->process($gateway->simulate($payment, $data['outcome']));

        return to_route('commerce.orders.show', $order);
    }

    public function webhook(Request $request, string $provider, PaymentGateway $gateway, PaymentLifecycle $lifecycle): JsonResponse
    {
        abort_unless($provider === 'development', 404);
        $lifecycle->process($gateway->verifyWebhook($request->getContent(), $request->header('X-Payment-Signature', '')));

        return response()->json(['received' => true]);
    }
}
