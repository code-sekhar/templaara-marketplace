<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Analytics\FinancialFacts;
use App\Services\Commerce\Money;
use Illuminate\Support\Facades\DB;

/** A bounded, owner-scoped read projection. It never grants access or writes commercial state. */
final class CustomerWorkspace
{
    /** @return array<string,mixed> */
    public function forUser(User $user): array
    {
        $paid = app(FinancialFacts::class)->paidOrders()->where('o.user_id', $user->id);
        $licenses = DB::table('licenses as l')->join('entitlements as e', 'e.id', '=', 'l.entitlement_id')
            ->where('e.user_id', $user->id)->where('l.status', 'active')->where('e.status', 'active')
            ->whereNull('l.revoked_at')->whereNull('e.revoked_at')
            ->where(fn ($q) => $q->whereNull('l.expires_at')->orWhere('l.expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('e.expires_at')->orWhere('e.expires_at', '>', now()))
            ->whereIn('e.order_id', (clone $paid)->select('o.id'));
        $tickets = DB::table('support_tickets')->where('customer_user_id', $user->id);
        $counts = [
            'purchases' => (clone $paid)->count(),
            'active_licenses' => $licenses->count(),
            'open_tickets' => (clone $tickets)->whereIn('status', ['awaiting_seller', 'awaiting_customer'])->count(),
            'invoices' => DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')->where('orders.user_id', $user->id)->where('invoices.kind', 'invoice')->count(),
        ];
        $orders = (clone $paid)->orderByDesc('o.paid_at')->orderByDesc('o.id')
            ->limit(4)->get(['o.id', 'o.order_number', 'o.currency', 'o.grand_total', 'o.paid_at']);
        $items = $orders->isEmpty() ? collect() : DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))
            ->orderBy('id')->get(['order_id', 'product_name'])->groupBy('order_id');

        return ['counts' => $counts,
            'recent_purchases' => $orders->map(fn ($o) => [
                'reference' => $o->order_number, 'currency' => $o->currency,
                'total' => Money::decimal((string) $o->grand_total), 'paid_at' => $o->paid_at,
                'products' => $items->get($o->id, collect())->take(2)->pluck('product_name')->values()->all(),
            ])->all(),
            'support_activity' => (clone $tickets)->orderByDesc('last_message_at')->orderByDesc('id')->limit(3)
                ->get(['public_uuid', 'ticket_number', 'subject', 'status', 'last_message_at'])
                ->map(fn ($t) => ['reference' => $t->public_uuid, 'number' => $t->ticket_number, 'subject' => $t->subject,
                    'status' => $t->status, 'last_activity_at' => $t->last_message_at])->all(),
        ];
    }
}
