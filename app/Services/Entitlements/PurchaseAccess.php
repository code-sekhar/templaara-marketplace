<?php

namespace App\Services\Entitlements;

use App\Enums\EntitlementStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductArtifactType;
use App\Models\Entitlement;

class PurchaseAccess
{
    public function valid(Entitlement $e): bool
    {
        $e->loadMissing(['item.order', 'artifact.release']);
        $item = $e->item;
        $order = $item->order;
        $artifact = $e->artifact;
        $settlement = $order->payments()->whereKey($order->getAttribute('settled_payment_id'))->where('status', PaymentStatus::Paid)->where('amount', $order->grand_total)->where('currency', $order->currency->value)->exists();

        return $e->status === EntitlementStatus::Active && $e->getAttribute('revoked_at') === null && (! $e->getAttribute('expires_at') || $e->getAttribute('expires_at')->isFuture())
         && $order->status === OrderStatus::Completed && $order->payment_status === PaymentStatus::Paid && $settlement
         && $e->user_id === $order->user_id && $e->getAttribute('order_id') === $order->id && $e->product_id === $item->product_id
         && $e->getAttribute('product_offer_id') === $item->product_offer_id && $e->product_release_id === $item->product_release_id
         && $artifact->product_release_id === $e->product_release_id && $artifact->getAttribute('type') === ProductArtifactType::SourcePackage
         && $artifact->release->product_id === $item->product_id && $artifact->release->version === $item->getAttribute('version');
    }
}
