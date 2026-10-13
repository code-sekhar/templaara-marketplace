<?php

namespace App\Services\Payouts;

use App\Models\AuthorEarningLedgerEntry;
use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;

class FinancialBalances
{
    /** @return array<int,array<string,mixed>> */
    public function all(?int $author = null, bool $report = false): array
    {
        $q = AuthorEarningLedgerEntry::join('author_earnings as e', 'e.id', '=', 'author_earning_ledger_entries.author_earning_id')->leftJoin('refund_compensations as c', 'c.refund_item_id', '=', 'author_earning_ledger_entries.refund_item_id');
        if ($author !== null) {
            $q->where('author_earning_ledger_entries.author_user_id', $author);
        }

        return $q->selectRaw("author_earning_ledger_entries.currency,
 SUM(CASE WHEN type='earning_pending' THEN amount ELSE 0 END) AS earned,
 SUM(CASE WHEN type='earning_available' THEN amount ELSE 0 END) AS released,
 SUM(CASE WHEN type='payout_reserved' THEN amount ELSE 0 END) AS reserved_total,
 SUM(CASE WHEN type='payout_released' THEN amount ELSE 0 END) AS returned,
 SUM(CASE WHEN type='payout_paid' THEN amount ELSE 0 END) AS paid,
 SUM(CASE WHEN type='refund_reversal' THEN amount ELSE 0 END) AS adjustment,
 SUM(CASE WHEN type='refund_reversal' AND c.earning_bucket='pending' THEN amount ELSE 0 END) AS pending_refund,
 SUM(CASE WHEN type='refund_reversal' AND c.earning_bucket='available' THEN amount ELSE 0 END) AS available_refund,
 SUM(CASE WHEN type='earning_pending' THEN e.gross_amount ELSE 0 END) AS gross,
 SUM(CASE WHEN type='earning_pending' THEN e.commission_amount ELSE 0 END) AS commission,
 SUM(CASE WHEN type='refund_reversal' THEN c.gross_amount ELSE 0 END) AS gross_refunded,
 SUM(CASE WHEN type='refund_reversal' THEN c.commission_amount ELSE 0 END) AS commission_reversed")
            ->groupBy('author_earning_ledger_entries.currency')->orderBy('author_earning_ledger_entries.currency')->get()->map(function ($r) use ($report) {
                $v = fn (string $name) => BigDecimal::of((string) $r->getAttribute($name));
                $money = fn (BigDecimal $n) => Money::decimal((string) $n);
                $raw = $v('released')->minus($v('reserved_total'))->plus($v('returned'))->minus($v('available_refund'));
                $out = ['currency' => $r->getAttribute('currency'), 'earned' => $money($v('earned')), 'pending' => $money($v('earned')->minus($v('released'))->minus($v('pending_refund'))), 'available' => $money($raw->isNegative() ? BigDecimal::zero() : $raw), 'net_position' => $money($raw), 'reserved' => $money($v('reserved_total')->minus($v('returned'))->minus($v('paid'))), 'paid' => $money($v('paid')), 'refund_adjustment' => $money($v('adjustment')), 'net_earned' => $money($v('earned')->minus($v('adjustment'))), 'outstanding_adjustment' => $money($raw->isNegative() ? $raw->negated() : BigDecimal::zero())];
                if ($report) {
                    $out = [...$out, 'gross' => $money($v('gross')), 'commission' => $money($v('commission')), 'gross_refunded' => $money($v('gross_refunded')), 'commission_reversed' => $money($v('commission_reversed')), 'net_sales' => $money($v('gross')->minus($v('gross_refunded'))), 'net_commission' => $money($v('commission')->minus($v('commission_reversed')))];
                }

                return $out;
            })->all();
    }

    public function available(int $author, string $currency): string
    {
        foreach ($this->all($author) as $r) {
            if ($r['currency'] === $currency) {
                return $r['available'];
            }
        }

        return '0.00';
    }
}
