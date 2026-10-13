<?php

namespace App\Services\Refunds;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutRequest;
use App\Models\Refund;
use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundPresentation
{
    /** @return array<string,mixed> */
    public function row(Refund $r, bool $admin = false): array
    {
        return ['uuid' => $r->getAttribute('public_uuid'), 'reference' => $r->getAttribute('reference'), 'order' => $r->order->order_number, 'currency' => $r->getAttribute('currency'), 'amount' => $r->getAttribute('amount'), 'status' => $r->status->value, 'reason_code' => $r->getAttribute('reason_code'), 'requested_at' => $r->getAttribute('requested_at')->toIso8601String(), 'completed_at' => $r->getAttribute('completed_at')?->toIso8601String(), ...($admin ? ['customer' => $r->order->getAttribute('customer_name')] : [])];
    }

    /** @return array<string,mixed> */
    public function detail(Refund $r, bool $admin = false): array
    {
        $r->loadMissing('order');
        $items = $r->items()->get();
        $purchased = OrderItem::whereIn('id', $items->pluck('order_item_id'))->with(['earning' => fn ($q) => $q->withSum(['ledger as refund_paid_exposure' => fn ($q) => $q->where('type', 'payout_paid')], 'amount'), 'entitlement' => fn ($q) => $q->withCount(['accesses', 'activations']), 'entitlement.license'])->get()->keyBy('id');
        $exposure = $admin ? PayoutRequest::whereIn('author_user_id', $purchased->pluck('earning.author_user_id')->filter())->where('currency', $r->getAttribute('currency'))->whereIn('status', ['requested', 'approved', 'processing'])->get(['author_user_id', 'status'])->groupBy('author_user_id') : collect();
        $rows = $items->map(function ($i) use ($purchased, $admin, $exposure) {
            $item = $purchased[$i->getAttribute('order_item_id')];
            $e = $item->earning;
            $ent = $item->entitlement;

            return ['product' => $item->getAttribute('product_name'), 'version' => $item->getAttribute('version'), 'amount' => $i->getAttribute('amount'), ...($admin ? ['review_facts' => $i->getAttribute('review_facts'), 'license_status' => $ent?->license?->status->value, 'download_count' => $ent?->getAttribute('accesses_count') ?? 0, 'activation_count' => $ent?->getAttribute('activations_count') ?? 0, 'earning_status' => $e?->status->value, 'author_amount' => $i->getAttribute('author_amount'), 'commission_amount' => $i->getAttribute('commission_amount'), 'payout_exposure' => $e ? ($exposure->get($e->author_user_id) ?? collect())->map(fn ($p) => $p->status->value)->all() : [], 'paid_exposure' => $e ? Money::decimal((string) ($e->getAttribute('refund_paid_exposure') ?? '0.00')) : '0.00'] : [])];
        })->all();

        return [...$this->row($r, $admin), 'customer_reason' => $r->getAttribute('customer_reason'), 'purchase_date' => $r->order->getAttribute('paid_at'), 'window_days' => $r->getAttribute('window_days'), 'window_ends_at' => $r->getAttribute('window_ends_at'), 'failure_reason' => $r->getAttribute('failure_reason'), 'cancellation_reason' => $r->getAttribute('cancellation_reason'), 'items' => $rows, 'events' => $r->events()->orderBy('id')->get()->map(fn ($e) => ['status' => $e->getAttribute('status'), 'previous_status' => $e->getAttribute('previous_status'), 'reason' => $e->getAttribute('reason'), 'occurred_at' => $e->getAttribute('occurred_at')])->all(), ...($admin ? ['admin_note' => $r->getAttribute('admin_note'), 'external_reference' => $r->getAttribute('external_reference')] : [])];
    }

    /** @return array<string,mixed> */
    public function item(OrderItem $i): array
    {
        return $this->items(collect([$i]))[$i->id];
    }

    /**
     * @param  Collection<int,OrderItem>  $items
     * @return array<int,array<string,mixed>>
     */
    public function items(Collection $items): array
    {
        $claims = DB::table('purchase_refund_states as s')->leftJoin('refund_items as i', 'i.id', '=', 's.completed_refund_item_id')->whereIn('s.order_item_id', $items->pluck('id'))->selectRaw('s.order_item_id, COALESCE(i.refund_id,s.active_refund_id) AS refund_id')->get()->keyBy('order_item_id');
        $refunds = Refund::whereIn('id', $claims->pluck('refund_id')->filter())->get()->keyBy('id');

        return $items->mapWithKeys(fn ($i) => [$i->id => $this->describeItem($i, $refunds->get($claims->get($i->id)?->refund_id))])->all();
    }

    /** @return array<string,mixed> */
    private function describeItem(OrderItem $i, ?Refund $r): array
    {
        $eligible = false;
        $explanation = 'This purchase is not eligible for a new refund request.';
        try {
            $o = $i->order;
            $eligible = $o->status->value === 'completed' && $o->payment_status->value === 'paid' && $o->getAttribute('paid_at') && now()->lessThanOrEqualTo($o->getAttribute('paid_at')->addDays(app(RefundWindow::class)->forOrder($o))) && BigDecimal::of($i->line_total)->isPositive() && ! $r;
            if (! $r && $eligible) {
                $explanation = 'Eligible to request; approval requires administrator review.';
            }
        } catch (ValidationException) {
            $explanation = 'Refund policy is unavailable. Contact Templaara.';
        }

        return ['item_id' => $i->id, 'refund_status' => $r?->status->value ?? 'none', 'refund_eligible' => $eligible, 'refund_explanation' => $explanation, 'refund' => $r ? ['uuid' => $r->getAttribute('public_uuid'), 'reference' => $r->getAttribute('reference'), 'status' => $r->status->value, 'completed_at' => $r->getAttribute('completed_at')] : null];
    }

    public function orderStatus(Order $o): string
    {
        $count = DB::table('purchase_refund_states')->whereIn('order_item_id', $o->items()->select('id'))->whereNotNull('completed_refund_item_id')->count();

        return $count === 0 ? 'none' : ($count === $o->items()->count() ? 'full' : 'partial');
    }

    /** @return array<int,array<string,mixed>> */
    public function report(): array
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('refund_compensations as c', 'c.order_item_id', '=', 'i.id')->where('o.payment_status', 'paid')->where('o.status', 'completed')->selectRaw("i.currency, SUM(i.line_total) AS gross, SUM(COALESCE(i.commission_amount,0)) AS commission, SUM(COALESCE(c.gross_amount,0)) AS refunded, SUM(COALESCE(c.commission_amount,0)) AS reversed, SUM(COALESCE(c.author_amount,0)) AS adjustment, SUM(CASE WHEN i.seller_type='platform' THEN i.line_total ELSE 0 END) AS official, SUM(CASE WHEN i.seller_type='platform' THEN COALESCE(c.gross_amount,0) ELSE 0 END) AS official_refunded")->groupBy('i.currency')->orderBy('i.currency')->get()->map(function ($r) {
            $v = fn (string $key) => BigDecimal::of((string) $r->$key);
            $money = fn (BigDecimal $n) => Money::decimal((string) $n);

            return ['currency' => $r->currency, 'original_gross' => $money($v('gross')), 'original_commission' => $money($v('commission')), 'gross_refunded' => $money($v('refunded')), 'commission_reversed' => $money($v('reversed')), 'author_adjustment' => $money($v('adjustment')), 'net_sales' => $money($v('gross')->minus($v('refunded'))), 'net_commission' => $money($v('commission')->minus($v('reversed'))), 'official_refunded' => $money($v('official_refunded')), 'platform_net' => $money($v('official')->minus($v('official_refunded'))->plus($v('commission'))->minus($v('reversed')))];
        })->all();
    }
}
