<?php

namespace App\Services\Refunds;

use App\Models\Refund;
use Illuminate\Support\Facades\DB;

class ReconcileRefunds
{
    /** @return array{checked:int,errors:int} */
    public function run(?string $reference = null): array
    {
        $counts = ['checked' => 0, 'errors' => 0];
        Refund::where('status', 'completed')->when($reference, fn ($q) => $q->where('reference', $reference))->chunkById(100, function ($rows) use (&$counts) {
            foreach ($rows as $r) {
                $counts['checked']++;
                $broken = false;
                foreach ($r->items as $i) {
                    $c = DB::table('refund_compensations')->where('refund_item_id', $i->id)->first();
                    $s = DB::table('purchase_refund_states')->where('order_item_id', $i->getAttribute('order_item_id'))->first();
                    $e = DB::table('entitlements')->where('order_item_id', $i->getAttribute('order_item_id'))->first();
                    $l = $e ? DB::table('licenses')->where('entitlement_id', $e->id)->first() : null;
                    if (! $c || ! $s || $s->completed_refund_item_id !== $i->id || ! $e || ! $l || $e->status !== 'revoked' || $l->status !== 'revoked' || ($i->getAttribute('author_earning_id') && ! DB::table('author_earning_ledger_entries')->where('refund_item_id', $i->id)->where('type', 'refund_reversal')->exists())) {
                        $broken = true;
                    }
                }if (! $r->events()->where('status', 'completed')->exists()) {
                    $broken = true;
                }$counts['errors'] += (int) $broken;
            }
        });

        return $counts;
    }
}
