<?php

namespace App\Services\Support;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SupportEligibility
{
    /**
     * @return Builder<OrderItem> */
    public function query(User $u, bool $period = true): Builder
    {
        $q = OrderItem::query()->whereHas('order', fn ($q) => $q->where('user_id', $u->id)->where('status', 'completed')->where('payment_status', 'paid')->whereNotNull('paid_at')->whereHas('payments', fn ($p) => $p->whereColumn('payments.id', 'orders.settled_payment_id')->where('status', 'paid')->whereColumn('amount', 'orders.grand_total')->whereColumn('currency', 'orders.currency')))
            ->whereHas('entitlement', fn ($e) => $e->where('status', 'active')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->toIso8601String()))->where('user_id', $u->id)->whereColumn('entitlements.order_id', 'order_items.order_id')->whereColumn('entitlements.product_id', 'order_items.product_id')->whereColumn('entitlements.product_offer_id', 'order_items.product_offer_id')->whereColumn('entitlements.product_release_id', 'order_items.product_release_id')
                ->whereHas('license', fn ($l) => $l->where('user_id', $u->id)->where('status', 'active')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->toIso8601String()))->whereColumn('licenses.order_item_id', 'order_items.id')->whereColumn('licenses.product_id', 'order_items.product_id')->whereColumn('licenses.product_release_id', 'order_items.product_release_id'))
                ->whereHas('artifact', fn ($a) => $a->where('type', 'source_package')->whereColumn('product_release_id', 'order_items.product_release_id')->whereHas('release', fn ($r) => $r->whereColumn('product_id', 'order_items.product_id')->whereColumn('version', 'order_items.version'))))
            ->whereIn('seller_type', ['author', 'platform'])->whereNotExists(fn ($r) => $r->selectRaw('1')->from('purchase_refund_states')->whereColumn('order_item_id', 'order_items.id')->whereNotNull('completed_refund_item_id'));
        if ($period) {
            $q->where('support_period_days', '>', 0)->whereHas('order', fn ($o) => $o->whereRaw(DB::getDriverName() === 'pgsql' ? "paid_at+order_items.support_period_days*INTERVAL '1 day'>?" : "datetime(paid_at,'+' || order_items.support_period_days || ' days')>datetime(?)", [now()->toIso8601String()]));
        }

        return $q;
    }

    public function eligible(User $u, OrderItem $i, bool $period = true): bool
    {
        return $this->query($u, $period)->whereKey($i->id)->exists();
    }

    public function ends(OrderItem $i): Carbon
    {
        return Carbon::parse($i->order->getAttribute('paid_at'))->addDays((int) $i->getAttribute('support_period_days'));
    }

    /**
     * @return array<string,mixed> */
    public function context(OrderItem $i): array
    {
        return [...$i->only(['product_name', 'version', 'offer_name', 'license_type', 'support_period_days']), 'support_ends_at' => $this->ends($i)->toIso8601String(), 'purchase' => $i->entitlement->getAttribute('public_id')];
    }
}
