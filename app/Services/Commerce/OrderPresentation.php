<?php

namespace App\Services\Commerce;

use App\Enums\EntitlementStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Invoicing\InvoicePresentation;
use App\Services\Refunds\RefundPresentation;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Support\SupportPresentation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderPresentation
{
    /** @return array<string,mixed> */
    public function summary(Order $order): array
    {
        return [...$order->only(['order_number', 'customer_name', 'grand_total', 'placed_at', 'paid_at', 'cancelled_at', 'invoice_number']),
            'status' => $order->status->value, 'payment_status' => $order->payment_status->value, 'currency' => $order->currency->value];
    }

    /** @return array<string,mixed> */
    public function detail(Order $order, bool $admin = false): array
    {
        $order->load(['items.product', 'items.entitlement.license', 'payments' => fn ($q) => $q->with('checkout')->orderByDesc('id')]);

        foreach ($order->items as $item) {
            $item->setRelation('order', $order);
        }

        $reviewEntries = app(ReviewPresentation::class)->entries(request()->user(), $order->items->pluck('product')->unique('id')->values());
        $refunds = app(RefundPresentation::class)->items($order->items);
        $supportEntries = app(SupportPresentation::class)->entries(request()->user(), $order->items);

        return [...$this->summary($order), ...$order->only(['company_name', 'billing_email', 'phone', 'address_line_1', 'address_line_2', 'city', 'state_region', 'postal_code', 'country_code', 'subtotal', 'discount_total', 'tax_total']),
            'documents' => Invoice::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($doc) => app(InvoicePresentation::class)->summary($doc, $admin))->all(),
            'refund_status' => app(RefundPresentation::class)->orderStatus($order),
            'software_access' => $order->items->isNotEmpty() && $order->items->every(fn ($item) => $item->entitlement && $item->entitlement->license)
                ? ($order->items->every(fn ($item) => $item->entitlement->status === EntitlementStatus::Active && (! $item->entitlement->getAttribute('expires_at') || $item->entitlement->getAttribute('expires_at')->isFuture())) ? 'active' : 'inactive') : 'pending',
            'items' => $order->items->map(fn ($i) => ['support_entry' => $supportEntries[$i->id], 'review_entry' => $reviewEntries[$i->product_id], ...$refunds[$i->id], ...$i->only(['product_name', 'product_slug', 'offer_name', 'offer_description', 'license_type', 'unit_price', 'quantity', 'line_total', 'currency', 'support_period_days', 'updates_period_days', 'version', 'origin', 'seller_name'])])->all(),
            'payments' => $order->payments->map(fn (Payment $payment) => [
                ...$payment->only(['amount', 'currency', 'failure_code', 'failure_message', 'paid_at', 'created_at']),
                'status' => $payment->status->value,
                'provider_name' => match ($payment->provider) {
                    'stripe' => 'Stripe', 'razorpay' => 'Razorpay', default => 'Development'
                },
                ...($admin && $payment->checkout ? ['external' => $payment->checkout->only(['provider_order_id', 'provider_payment_id', 'state', 'problem_code', 'reconciled_at'])] : []),
                ...($admin ? $payment->only(['provider', 'provider_payment_id', 'provider_order_id']) : []),
                'simulation_key' => $payment->provider === 'development' && app(DevelopmentGateway::class)->available() && $order->user_id === request()->user()?->id && $payment->status->value === 'pending' && $order->status->value === 'pending_payment' ? $payment->idempotency_key : null,
            ])->all()];
    }

    /** @return array<string,mixed> */
    public function listing(Request $request, bool $admin = false): array
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['pending_payment', 'payment_failed', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['pending', 'paid', 'failed', 'cancelled'])], 'currency' => ['nullable', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')]]);
        $query = Order::query();
        if (! $admin) {
            $query->where('user_id', $request->user()->id);
        }
        foreach (['status', 'payment_status', 'currency'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(order_number) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER(customer_name) LIKE ? ESCAPE '\\'", [$term]));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('placed_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('placed_at', '<=', $filters['to']);
        }

        return ['admin' => $admin, 'filters' => $filters, 'records' => $query->orderByDesc('placed_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($order) => $this->summary($order))];
    }
}
