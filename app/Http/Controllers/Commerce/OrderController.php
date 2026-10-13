<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\OrderPresentation;
use App\Services\Commerce\PaymentLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, OrderPresentation $presenter): Response
    {
        return Inertia::render('orders/Index', $presenter->listing($request, $request->routeIs('admin.orders.*')));
    }

    public function show(Request $request, Order $order, OrderPresentation $presenter): Response
    {
        Gate::authorize('view', $order);
        $admin = $request->routeIs('admin.orders.*');
        $externalPending = $order->payments()->where('status', 'pending')->whereIn('provider', ['stripe', 'razorpay'])->exists();

        return Inertia::render('orders/Show', ['order' => $presenter->detail($order, $admin), 'admin' => $admin, 'canCancel' => ! $externalPending && ($admin ? $request->user()->can('manage', $order) : $request->user()->can('cancel', $order)),
            'canRetry' => ! $admin && $request->user()->can('retry', $order)]);
    }

    public function retry(Request $request, Order $order, PaymentLifecycle $lifecycle): RedirectResponse
    {
        $order = $lifecycle->retry($request->user(), $order);
        if ($checkout = $order->payments()->where('status', 'pending')->latest('id')->first()?->checkout) {
            app(ExternalPayments::class)->prepare($checkout);

            return to_route('commerce.payments.status', $order);
        }

        return to_route('commerce.orders.show', $order);
    }

    public function cancel(Request $request, Order $order, PaymentLifecycle $lifecycle): RedirectResponse
    {
        $admin = $request->routeIs('admin.orders.*');
        $lifecycle->cancel($request->user(), $order, $admin);

        return to_route($admin ? 'admin.orders.show' : 'commerce.orders.show', $order);
    }
}
