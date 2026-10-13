<?php

namespace App\Services\Earnings;

use App\Enums\AuthorEarningStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Commerce\AuthorEarningRecorded;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EarningProvisioner
{
    public function provision(Order $target): int
    {
        return DB::transaction(function () use ($target): int {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $p = $order->payments()->whereKey($order->getAttribute('settled_payment_id'))->first();
            if ($order->status !== OrderStatus::Completed || $order->payment_status !== PaymentStatus::Paid || ! $p || $p->status !== PaymentStatus::Paid || $p->amount !== $order->grand_total || $p->currency !== $order->currency->value) {
                throw ValidationException::withMessages(['earning' => 'Only verified paid orders can provision earnings.']);
            }
            $created = 0;
            foreach ($order->items()->where('seller_type', 'author')->orderBy('id')->get() as $item) {
                if (AuthorEarning::where('order_item_id', $item->id)->exists()) {
                    continue;
                }
                $at = now();
                $e = new AuthorEarning;
                $e->forceFill(['public_uuid' => (string) Str::uuid(), 'author_user_id' => $item->getAttribute('author_user_id'), 'author_profile_id' => $item->getAttribute('author_profile_id'), 'order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $item->product_id, 'product_release_id' => $item->product_release_id, 'commission_policy_id' => $item->getAttribute('commission_policy_id'), 'currency' => $item->getAttribute('currency'), 'gross_amount' => $item->getAttribute('commissionable_amount'), 'commission_rate' => $item->getAttribute('commission_rate'), 'commission_amount' => $item->getAttribute('commission_amount'), 'author_amount' => $item->getAttribute('author_amount'), 'hold_days' => $item->getAttribute('earning_hold_days'), 'status' => AuthorEarningStatus::Pending, 'available_at' => $order->getAttribute('paid_at')->addDays($item->getAttribute('earning_hold_days')), 'created_at' => $at, 'updated_at' => $at])->save();
                (new AuthorEarningLedgerEntry)->forceFill(['author_earning_id' => $e->id, 'author_user_id' => $e->author_user_id, 'currency' => $e->getAttribute('currency'), 'type' => 'earning_pending', 'amount' => $e->getAttribute('author_amount'), 'occurred_at' => $at])->save();
                AuthorEarningRecorded::dispatch($e->id);
                $created++;
            }

            return $created;
        }, 3);
    }
}
