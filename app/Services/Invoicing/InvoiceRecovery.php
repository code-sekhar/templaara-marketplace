<?php

namespace App\Services\Invoicing;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Settings\MarketplaceSettings;

final class InvoiceRecovery
{
    /** @return array{invoices:int,credits:int,errors:int} */
    public function run(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Limit must be 1–500.');
        }
        $result = ['invoices' => 0, 'credits' => 0, 'errors' => 0];
        $enabled = app(MarketplaceSettings::class)->value('invoicing', 'enabled');
        $orders = Order::where('status', 'completed')->where('payment_status', 'paid')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.order_id', 'orders.id')->where('kind', 'invoice'))
            ->when(! $enabled, fn ($q) => $q->where('tax_snapshot->policy->enabled', true))
            ->orderBy('id')->limit($limit)->get();
        foreach ($orders as $order) {
            try {
                if (app(IssueInvoice::class)->issue($order)) {
                    $result['invoices']++;
                }
            } catch (\Throwable) {
                $result['errors']++;
            }
        }
        $refunds = Refund::where('status', 'completed')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.refund_id', 'refunds.id'))
            ->when(! $enabled, fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('order', fn ($q) => $q->where('tax_snapshot->policy->enabled', true))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.order_id', 'refunds.order_id')->where('kind', 'invoice'))))
            ->orderBy('id')->limit($limit)->get();
        foreach ($refunds as $refund) {
            try {
                if (app(IssueInvoice::class)->credit($refund)) {
                    $result['credits']++;
                }
            } catch (\Throwable) {
                $result['errors']++;
            }
        }

        return $result;
    }
}
