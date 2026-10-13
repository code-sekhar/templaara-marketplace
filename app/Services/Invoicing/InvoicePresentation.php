<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InvoicePresentation
{
    /** @return array<string,mixed> */
    public function summary(Invoice $invoice, bool $admin = false): array
    {
        return [...$invoice->only(['public_uuid', 'number', 'kind', 'status', 'order_number', 'currency', 'grand_total', 'tax_total', 'issued_at']),
            'url' => route($admin ? 'admin.invoices.show' : 'account.invoices.show', $invoice, false),
            'pdf_url' => route($admin ? 'admin.invoices.pdf' : 'account.invoices.pdf', $invoice, false)];
    }

    /** @return array<string,mixed> */
    public function detail(Invoice $invoice, bool $admin): array
    {
        return [...$this->summary($invoice, $admin), ...$invoice->only(['subtotal', 'taxable_amount', 'customer_snapshot', 'issuer_snapshot', 'payment_display', 'provenance']),
            'inclusive' => $invoice->provenance === 'legacy_undetermined' ? null : $invoice->policy_snapshot['prices_include_tax'],
            'original' => $invoice->original ? $this->summary($invoice->original, $admin) : null,
            'refund_reference' => $invoice->refund?->getAttribute('reference'),
            'items' => $invoice->items()->orderBy('id')->get()->map(fn ($item) => [...$item->only(['product_name', 'offer_name', 'version', 'license_type', 'seller_name', 'origin', 'quantity', 'unit_price', 'taxable_amount', 'tax_amount', 'grand_total', 'tax_type', 'tax_rate', 'jurisdiction']), 'components' => $item->tax_snapshot['components'], 'service_code' => $item->tax_snapshot['service_code'] ?? null])->all(),
            'credits' => $invoice->credits()->orderBy('id')->get()->map(fn ($credit) => $this->summary($credit, $admin))->all()];
    }

    /** @return array<string,mixed> */
    public function listing(Request $request, bool $admin): array
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'currency' => ['nullable', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'kind' => ['nullable', Rule::in(['invoice', 'credit_note'])], 'tax_type' => ['nullable', Rule::in(['NONE', 'CGST_SGST', 'IGST', 'VAT', 'SALES_TAX', 'GST', 'OTHER'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')]]);
        $query = Invoice::query();
        if (! $admin) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id));
        }
        foreach (['currency', 'kind'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(number) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER(order_number) LIKE ? ESCAPE '\\'", [$term]));
        }
        if (! empty($filters['tax_type'])) {
            $query->whereHas('items', fn ($q) => $q->where('tax_type', $filters['tax_type']));
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (! empty($filters[$field])) {
                $boundary = CarbonImmutable::createFromFormat('!Y-m-d', $filters[$field], 'UTC');
                $query->where('issued_at', $field === 'from' ? '>=' : '<', ($field === 'from' ? $boundary : $boundary->addDay())->toIso8601String());
            }
        }

        return ['admin' => $admin, 'filters' => $filters, 'records' => $query->orderByDesc('issued_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($doc) => $this->summary($doc, $admin))];
    }
}
