<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Commerce\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IssueInvoice
{
    public function issue(Order $target): ?Invoice
    {
        return DB::transaction(function () use ($target) {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $existing = Invoice::where('order_id', $order->id)->where('kind', 'invoice')->first();
            if ($existing) {
                return $existing;
            }
            $payment = $order->payments()->whereKey($order->getAttribute('settled_payment_id'))->first();
            if ($order->status->value !== 'completed' || $order->payment_status->value !== 'paid' || ! $payment || $payment->status->value !== 'paid' || $payment->amount !== $order->grand_total || $payment->currency !== $order->currency->value) {
                throw ValidationException::withMessages(['invoice' => 'Only an exact verified paid purchase can be invoiced.']);
            }
            $snapshot = $order->getAttribute('tax_snapshot');
            $policy = $snapshot['policy'] ?? app(TaxPolicy::class)->current();
            if (! $policy['enabled']) {
                return null;
            }
            app(TaxPolicy::class)->validate($policy);
            $items = $order->items()->orderBy('id')->get();
            if ($items->isEmpty() || Money::sum($items->pluck('line_total')) !== $order->subtotal) {
                throw new \LogicException('Purchased invoice lines are incomplete.');
            }
            $lines = [];
            foreach ($items as $item) {
                $tax = $item->getAttribute('tax_snapshot') ?? ['commercial_amount' => $item->line_total, 'taxable_amount' => $item->line_total, 'tax_amount' => '0.00', 'grand_total' => $item->line_total, 'type' => 'NONE', 'rate' => '0.00', 'jurisdiction' => $order->getAttribute('country_code'), 'components' => [], 'reason' => 'historical_tax_undetermined', 'inclusive' => false, 'additional_tax' => '0.00', 'service_code' => null];
                $lines[] = [...$item->only(['product_name', 'offer_name', 'version', 'license_type', 'seller_name', 'origin', 'quantity', 'unit_price']), 'order_item_id' => $item->id,
                    'taxable_amount' => $tax['taxable_amount'], 'tax_amount' => $tax['tax_amount'], 'grand_total' => $tax['grand_total'], 'tax_type' => $tax['type'], 'tax_rate' => $tax['rate'], 'jurisdiction' => $tax['jurisdiction'], 'tax_snapshot' => $tax];
            }
            if (Money::sum(array_column($lines, 'grand_total')) !== $order->grand_total) {
                throw new \LogicException('Invoice does not match settled amount.');
            }
            $customer = $order->only(['customer_name', 'company_name', 'billing_email', 'phone', 'address_line_1', 'address_line_2', 'city', 'state_region', 'postal_code', 'country_code', 'customer_tax_id', 'customer_gstin', 'gst_state_code']);

            return $this->persist('invoice', $order, $policy, $customer, $lines, $payment->provider, $snapshot ? 'checkout_snapshot' : 'legacy_undetermined');
        }, 3);
    }

    public function credit(Refund $target): ?Invoice
    {
        $original = $this->issue($target->order);
        if (! $original) {
            return null;
        }

        return DB::transaction(function () use ($target, $original) {
            User::whereKey($target->customer_user_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($target->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($refund->status->value !== 'completed') {
                throw ValidationException::withMessages(['invoice' => 'Credit notes require a completed refund.']);
            }
            if ($existing = Invoice::where('refund_id', $refund->id)->first()) {
                return $existing;
            }
            $lines = [];
            foreach ($refund->items()->orderBy('order_item_id')->get() as $item) {
                $line = $original->items()->where('order_item_id', $item->getAttribute('order_item_id'))->firstOrFail();
                if ($item->getAttribute('amount') !== $line->grand_total || ! DB::table('refund_compensations')->where('refund_item_id', $item->id)->exists()) {
                    throw new \LogicException('Credit requires exact compensated purchased items.');
                }
                $lines[] = [...$line->only(['order_item_id', 'product_name', 'offer_name', 'version', 'license_type', 'seller_name', 'origin', 'quantity', 'unit_price', 'taxable_amount', 'tax_amount', 'grand_total', 'tax_type', 'tax_rate', 'jurisdiction', 'tax_snapshot']), 'original_item_id' => $line->id, 'refund_item_id' => $item->id];
            }
            if (Money::sum(array_column($lines, 'grand_total')) !== $refund->getAttribute('amount')) {
                throw new \LogicException('Credit amount does not match completed refund.');
            }

            return $this->persist('credit_note', $order, $original->policy_snapshot, $original->customer_snapshot, $lines, $original->payment_display, $original->provenance, $original, $refund);
        }, 3);
    }

    /** @param array<string,mixed> $policy
     * @param  array<string,mixed>  $customer
     * @param  list<array<string,mixed>>  $lines
     */
    private function persist(string $kind, Order $order, array $policy, array $customer, array $lines, string $provider, string $provenance, ?Invoice $original = null, ?Refund $refund = null): Invoice
    {
        $at = now('UTC');
        $doc = new Invoice;
        $doc->forceFill(['public_uuid' => (string) Str::uuid(), 'number' => app(InvoiceNumber::class)->next($kind, $policy[$kind === 'invoice' ? 'invoice_prefix' : 'credit_prefix']), 'kind' => $kind, 'status' => 'issued',
            'order_id' => $order->id, 'order_number' => $order->order_number, 'original_invoice_id' => $original?->id, 'refund_id' => $refund?->id, 'currency' => $order->currency->value,
            'subtotal' => Money::sum(array_column($lines, 'unit_price')), 'taxable_amount' => Money::sum(array_column($lines, 'taxable_amount')), 'tax_total' => Money::sum(array_column($lines, 'tax_amount')), 'grand_total' => Money::sum(array_column($lines, 'grand_total')),
            'customer_snapshot' => $customer, 'issuer_snapshot' => $original->issuer_snapshot ?? array_intersect_key($policy, array_flip(['legal_name', 'trading_name', 'address_line_1', 'address_line_2', 'city', 'state_region', 'postal_code', 'country_code', 'contact_email', 'tax_registration_number', 'gstin', 'gst_state_code', 'customer_footer'])),
            'policy_snapshot' => $policy, 'payment_display' => $provider, 'provenance' => $provenance, 'issued_at' => $at])->save();
        foreach ($lines as $line) {
            (new InvoiceItem)->forceFill(['invoice_id' => $doc->id, ...$line])->save();
        }
        DB::table('invoice_events')->insert(['invoice_id' => $doc->id, 'type' => $kind.'_issued', 'occurred_at' => $at->toIso8601String()]);

        return $doc;
    }
}
