<?php

namespace App\Services\Earnings;

use App\Models\AuthorEarning;
use App\Services\Commerce\Money;
use App\Services\Payouts\FinancialBalances;

class EarningPresentation
{
    /** @return array<int,array<string,mixed>> */
    public function balances(?int $author = null): array
    {
        return app(FinancialBalances::class)->all($author);
    }

    /** @return array<int,array<string,mixed>> */
    public function report(): array
    {
        return app(FinancialBalances::class)->all(report: true);
    }

    /** @return array<string,mixed> */
    public function row(AuthorEarning $e, bool $admin = false): array
    {
        $data = ['uuid' => $e->getAttribute('public_uuid'), 'product' => $e->item->getAttribute('product_name'), 'offer' => $e->item->getAttribute('offer_name'), 'version' => $e->item->getAttribute('version'), 'order_number' => $e->order->order_number, 'currency' => $e->getAttribute('currency'), 'gross' => $e->getAttribute('gross_amount'), 'rate' => $e->getAttribute('commission_rate'), 'commission' => $e->getAttribute('commission_amount'), 'net' => $e->getAttribute('author_amount'), 'status' => $e->status->value, 'hold_days' => $e->getAttribute('hold_days'), 'available_at' => $e->getAttribute('available_at')->toIso8601String(), 'released_at' => $e->getAttribute('released_at')?->toIso8601String(), 'created_at' => $e->created_at->toIso8601String()];
        if (array_key_exists('refund_adjustment', $e->getAttributes())) {
            $data['refund_adjustment'] = Money::decimal((string) ($e->getAttribute('refund_adjustment') ?? '0.00'));
        }
        if ($admin) {
            $data['author'] = $e->author->name;
        }

        return $data;
    }
}
