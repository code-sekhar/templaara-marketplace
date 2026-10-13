<?php

namespace App\Http\Controllers\Commerce;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CheckoutRequest;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\PaymentGatewayManager;
use App\Services\Invoicing\CheckoutTax;
use App\Services\Invoicing\TaxIdentity;
use App\Services\Invoicing\TaxPolicy;
use App\Services\Settings\MarketplaceSettings;
use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function index(Request $request, CartService $carts, PaymentGateway $gateway): Response
    {
        $cart = $carts->view($request->user());
        $available = $gateway instanceof PaymentGatewayManager && $cart['currency'] ? $gateway->availableFor($cart['currency']) : $gateway->available();

        return Inertia::render('commerce/Checkout', ['cart' => $cart, 'defaults' => ['customer_name' => $request->user()->name, 'billing_email' => $request->user()->email], 'available' => $available, 'invoiceEnabled' => app(TaxPolicy::class)->current()['enabled']]);
    }

    public function quote(Request $request, CartService $carts): JsonResponse
    {
        $billing = $request->validate(['country_code' => ['required', Rule::in(Countries::codes())], 'gst_state_code' => ['nullable', Rule::in(TaxIdentity::stateCodes())], 'customer_gstin' => ['nullable', 'regex:'.TaxIdentity::GSTIN]]);

        return DB::transaction(function () use ($request, $carts, $billing) {
            app(MarketplaceSettings::class)->lock();
            $cart = $carts->view($request->user());
            abort_unless($cart['valid'], 422, 'Review your cart before requesting a tax quote.');
            $quote = app(CheckoutTax::class)->quote(array_column($cart['items'], 'price'), $billing);

            return response()->json(['subtotal' => $quote['commercial_subtotal'], 'taxable_subtotal' => $quote['taxable_subtotal'], 'tax_total' => $quote['tax_total'], 'grand_total' => $quote['grand_total'], 'inclusive' => $quote['policy']['prices_include_tax'],
                'hash' => app(CheckoutTax::class)->hash($quote, $billing, $cart['checkout_key']), 'lines' => array_map(fn ($line) => Arr::only($line, ['type', 'rate', 'components', 'taxable_amount', 'tax_amount', 'grand_total']), $quote['lines'])])->header('Cache-Control', 'private, no-store');
        });
    }

    public function store(CheckoutRequest $request, Checkout $checkout): RedirectResponse
    {
        $data = $request->validated();
        $order = $checkout->place($request->user(), $data['idempotency_key'], Arr::except($data, ['idempotency_key']));

        $payment = $order->payments()->where('status', 'pending')->latest('id')->first();
        if ($payment?->checkout) {
            app(ExternalPayments::class)->prepare($payment->checkout);

            return to_route('commerce.payments.status', $order)->header('Cache-Control', 'no-store');
        }

        return to_route('commerce.orders.show', $order);
    }
}
