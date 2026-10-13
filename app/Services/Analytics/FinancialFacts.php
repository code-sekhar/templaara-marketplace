<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only definitions shared by dashboard, reports and CSV. Never current offer prices. */
final class FinancialFacts
{
    public function paidOrders(): Builder
    {
        return DB::table('orders as o')->join('payments as settled', 'settled.id', '=', 'o.settled_payment_id')
            ->where('o.status', 'completed')->where('o.payment_status', 'paid')->whereNotNull('o.paid_at')
            ->where('settled.status', 'paid')->whereColumn('settled.order_id', 'o.id')
            ->whereColumn('settled.currency', 'o.currency')->whereColumn('settled.amount', 'o.grand_total');
    }

    public function period(Builder $q, string $date, ReportFilters $f): Builder
    {
        return $q->where($date, '>=', $f->sqlStart())->where($date, '<', $f->sqlEnd());
    }

    public function items(ReportFilters $f, bool $period = true): Builder
    {
        $q = $this->paidOrders()->join('order_items as i', 'i.order_id', '=', 'o.id')->join('products as p', 'p.id', '=', 'i.product_id');
        if ($period) {
            $this->period($q, 'o.paid_at', $f);
        }
        foreach (['currency' => 'i.currency', 'product' => 'i.product_id', 'author' => 'i.author_user_id', 'category' => 'p.category_id', 'origin' => 'i.origin'] as $key => $column) {
            if (isset($f->values[$key])) {
                $q->where($column, $f->values[$key]);
            }
        }

        return $q;
    }

    public function compensations(ReportFilters $f): Builder
    {
        return DB::table('refund_compensations as rc')->join('refund_items as ri', 'ri.id', '=', 'rc.refund_item_id')
            ->join('refunds as r', 'r.id', '=', 'ri.refund_id')->where('r.status', 'completed')
            ->where('r.completed_at', '<', $f->sqlEnd())->selectRaw('ri.order_item_id, SUM(rc.gross_amount) AS refund, SUM(rc.commission_amount) AS commission_reversed, SUM(rc.author_amount) AS author_reversed')->groupBy('ri.order_item_id');
    }

    public function sales(ReportFilters $f): Builder
    {
        return $this->items($f)->leftJoinSub($this->compensations($f), 'comp', 'comp.order_item_id', '=', 'i.id');
    }

    public function totals(ReportFilters $f): Builder
    {
        return $this->sales($f)->selectRaw("i.currency, COUNT(DISTINCT o.id) AS paid_orders, COUNT(*) AS paid_items, COUNT(DISTINCT o.user_id) AS purchasers, SUM(i.quantity) AS units, SUM(i.line_total) AS gross, SUM(COALESCE(comp.refund,0)) AS refunded, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained, SUM(COALESCE(i.commission_amount,0)) AS commission, SUM(COALESCE(i.author_amount,0)) AS author_earned, SUM(COALESCE(comp.commission_reversed,0)) AS commission_reversed, SUM(COALESCE(comp.author_reversed,0)) AS author_reversed, SUM(CASE WHEN comp.refund IS NOT NULL THEN 1 ELSE 0 END) AS refunded_items, COUNT(DISTINCT CASE WHEN comp.refund IS NOT NULL THEN o.id ELSE NULL END) AS affected_orders, SUM(CASE WHEN i.origin='templaara' THEN i.line_total ELSE 0 END) AS official_gross, SUM(CASE WHEN i.origin='templaara' THEN COALESCE(comp.refund,0) ELSE 0 END) AS official_refunded")->groupBy('i.currency')->orderBy('i.currency');
    }

    /** Historical net earned minus historical paid payouts; reservations do not erase obligations. */
    public function positions(ReportFilters $f): Builder
    {
        $q = DB::table('author_earning_ledger_entries as l')->where('l.occurred_at', '<', $f->sqlEnd());
        if (isset($f->values['currency'])) {
            $q->where('l.currency', $f->values['currency']);
        }
        if (isset($f->values['author'])) {
            $q->where('l.author_user_id', $f->values['author']);
        }

        return $q->selectRaw("l.author_user_id, l.currency, SUM(CASE WHEN type='earning_pending' THEN amount WHEN type IN ('refund_reversal','payout_paid') THEN -amount ELSE 0 END) AS outstanding, SUM(CASE WHEN type='earning_pending' THEN amount WHEN type='refund_reversal' THEN -amount ELSE 0 END) AS net_earned, SUM(CASE WHEN type='payout_paid' THEN amount ELSE 0 END) AS paid_lifetime")->groupBy('l.author_user_id', 'l.currency');
    }
}
