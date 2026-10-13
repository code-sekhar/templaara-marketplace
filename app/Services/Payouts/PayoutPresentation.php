<?php

namespace App\Services\Payouts;

use App\Models\PayoutRequest;
use App\Services\Commerce\Money;

class PayoutPresentation
{
    /** @return array<string,mixed> */
    public function row(PayoutRequest $p, bool $admin = false): array
    {
        $out = ['uuid' => $p->getAttribute('public_uuid'), 'reference' => $p->payout_number, 'currency' => $p->currency, 'amount' => $p->amount, 'status' => $p->status->value, 'method' => array_intersect_key($p->getAttribute('method_display_snapshot'), array_flip(['uuid', 'type', 'currency', 'country_code', 'label', 'bank_name', 'masked_account', 'status', 'is_default'])), 'requested_at' => $p->getAttribute('requested_at')->toIso8601String(), 'paid_at' => $p->getAttribute('paid_at')?->toIso8601String(), 'failure_reason' => $p->getAttribute('failure_reason'), 'cancellation_reason' => $p->getAttribute('cancellation_reason')];
        if ($admin) {
            $out['author'] = $p->author->name;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function detail(PayoutRequest $p, bool $admin = false): array
    {
        $p->load(['allocations.earning.item', 'events', 'author:id,name']);
        $data = $this->row($p, $admin);
        if ($admin) {
            $p->load('events.actor:id,name');
        }
        $data['allocations'] = $p->allocations->map(fn ($a) => ['amount' => $a->getAttribute('amount'), 'product' => $a->earning->item->getAttribute('product_name'), 'version' => $a->earning->item->getAttribute('version'), 'earning_uuid' => $a->earning->getAttribute('public_uuid')])->all();
        $data['events'] = $p->events->sortBy('id')->map(fn ($e) => ['status' => $e->getAttribute('status'), 'previous_status' => $e->getAttribute('previous_status'), 'reason' => $e->getAttribute('reason'), 'occurred_at' => $e->getAttribute('occurred_at')->toIso8601String(), ...($admin ? ['actor' => $e->actor->name] : [])])->values()->all();
        if ($admin) {
            $data = [...$data, 'admin_note' => $p->getAttribute('admin_note'), 'external_reference' => $p->getAttribute('external_reference')];
        }

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    public function report(): array
    {
        return PayoutRequest::selectRaw('currency,status,SUM(amount) AS total,COUNT(*) AS count')->groupBy('currency', 'status')->orderBy('currency')->orderBy('status')->get()->map(fn ($p) => ['currency' => $p->currency, 'status' => $p->status->value, 'amount' => Money::decimal((string) $p->getAttribute('total')), 'count' => $p->getAttribute('count')])->all();
    }
}
