<?php

namespace App\Listeners;

use App\Events\Commerce\OrderPaid;
use App\Events\Refunds\RefundCompleted;
use App\Models\Refund;
use App\Services\Invoicing\IssueInvoice;
use Illuminate\Support\Facades\Log;

final class IssuePurchaseInvoice
{
    public function handlePurchase(OrderPaid $event): void
    {
        try {
            app(IssueInvoice::class)->issue($event->order);
        } catch (\Throwable $error) {
            Log::warning('Invoice issuance deferred to recovery.', ['order_number' => $event->order->order_number, 'code' => 'invoice_recovery_required']);
        }
    }

    public function handleRefund(RefundCompleted $event): void
    {
        try {
            app(IssueInvoice::class)->credit(Refund::findOrFail($event->refundId));
        } catch (\Throwable $error) {
            Log::warning('Credit note deferred to recovery.', ['code' => 'credit_recovery_required']);
        }
    }
}
