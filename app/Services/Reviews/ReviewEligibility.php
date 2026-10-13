<?php

namespace App\Services\Reviews;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReviewEligibility
{
    /** The same correlated query serves qualification and live public visibility. */
    public function query(): Builder
    {
        return DB::table('order_items as ri')->join('orders as ro', 'ro.id', '=', 'ri.order_id')
            ->join('payments as rp', 'rp.id', '=', 'ro.settled_payment_id')
            ->join('entitlements as re', 're.order_item_id', '=', 'ri.id')
            ->join('licenses as rl', 'rl.entitlement_id', '=', 're.id')
            ->join('product_artifacts as ra', 'ra.id', '=', 're.product_artifact_id')
            ->join('product_releases as rr', 'rr.id', '=', 'ra.product_release_id')
            ->join('products as rproduct', 'rproduct.id', '=', 'ri.product_id')
            ->where('ro.status', 'completed')->where('ro.payment_status', 'paid')->whereNotNull('ro.paid_at')
            ->where('rp.status', 'paid')->whereColumn('rp.order_id', 'ro.id')->whereColumn('rp.amount', 'ro.grand_total')->whereColumn('rp.currency', 'ro.currency')
            ->where('re.status', 'active')->whereNull('re.revoked_at')->where(fn ($q) => $q->whereNull('re.expires_at')->orWhere('re.expires_at', '>', now()))
            ->where('rl.status', 'active')->whereNull('rl.revoked_at')->where(fn ($q) => $q->whereNull('rl.expires_at')->orWhere('rl.expires_at', '>', now()))
            ->whereColumn('re.user_id', 'ro.user_id')->whereColumn('re.order_id', 'ro.id')->whereColumn('re.product_id', 'ri.product_id')
            ->whereColumn('re.product_offer_id', 'ri.product_offer_id')->whereColumn('re.product_release_id', 'ri.product_release_id')
            ->whereColumn('rl.user_id', 'ro.user_id')->whereColumn('rl.order_item_id', 'ri.id')->whereColumn('rl.product_id', 'ri.product_id')->whereColumn('rl.product_release_id', 'ri.product_release_id')
            ->where('ra.type', 'source_package')->whereColumn('ra.product_release_id', 'ri.product_release_id')->whereColumn('rr.product_id', 'ri.product_id')->whereColumn('rr.version', 'ri.version')
            ->where(fn ($q) => $q->whereNull('rproduct.owner_id')->orWhereColumn('rproduct.owner_id', '!=', 'ro.user_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_refund_states as rs')->whereColumn('rs.order_item_id', 'ri.id')->whereNotNull('rs.completed_refund_item_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles as rm')->join('roles as role', 'role.id', '=', 'rm.role_id')->whereColumn('rm.model_id', 'ro.user_id')->where('rm.model_type', User::class)->where('role.name', 'admin')->where('role.guard_name', 'web')->where('rproduct.origin', 'templaara'));
    }

    public function purchase(User $customer, Product $product): ?OrderItem
    {
        $id = $this->query()->where('ro.user_id', $customer->id)->where('ri.product_id', $product->id)->orderBy('ro.paid_at')->orderBy('ri.id')->value('ri.id');

        return $id ? OrderItem::whereKey($id)->first() : null;
    }
}
