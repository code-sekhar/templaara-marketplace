<?php

namespace App\Services\Notifications;

use App\Enums\MarketplaceNotificationType as Type;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Services\Reviews\ProductRatingSummary;
use App\Services\Support\SupportEligibility;
use Illuminate\Support\Facades\DB;

/** Projects committed identities, never domain bodies, provider payloads or actor-supplied recipients. */
class NotificationFacts
{
    /** @return array<string,string> */
    public function sources(): array
    {
        return ['orders' => 'paid_at', 'refund_events' => 'occurred_at', 'payout_events' => 'occurred_at', 'author_application_events' => 'occurred_at', 'product_lifecycle_events' => 'occurred_at', 'author_earning_ledger_entries' => 'occurred_at', 'product_review_events' => 'occurred_at', 'support_ticket_events' => 'occurred_at'];
    }

    /** null means not ready for durable observation; [] means intentionally ignored.
     * @return array<int,array<string,mixed>>|null */
    public function project(string $source, \stdClass $e): ?array
    {
        $at = $e->{$this->sources()[$source]};
        if ($source === 'orders') {
            $o = Order::whereKey((int) $e->id)->first();
            if (! $o || $o->getRawOriginal('status') !== 'completed' || $o->getRawOriginal('payment_status') !== 'paid') {
                return [];
            }
            $p = $o->payments()->whereKey($o->getAttribute('settled_payment_id'))->where('status', 'paid')->first();
            if (! $p || $p->amount !== $o->grand_total || $p->currency !== $o->currency->value) {
                return [];
            }
            if (! $o->items()->exists() || $o->items()->whereDoesntHave('entitlement.license')->exists()) {
                return null;
            }

            if (app(SupportEligibility::class)->query($o->user, false)->where('order_id', $o->id)->count() !== $o->items()->count()) {
                return [];
            }

            return [$this->fact($o->user_id, Type::PurchaseReady, route('commerce.orders.show', $o, false), $o->order_number, $at)];
        }
        if (in_array($source, ['refund_events', 'payout_events'])) {
            $refund = $source === 'refund_events';
            $r = DB::table($refund ? 'refunds' : 'payout_requests')->where('id', (int) $e->{$refund ? 'refund_id' : 'payout_request_id'})->first();
            if (! $r) {
                return [];
            }
            $type = Type::tryFrom(($refund ? 'refund_' : 'payout_').$e->status);
            if (! $type) {
                return [];
            }

            return [$this->fact($r->{$refund ? 'customer_user_id' : 'author_user_id'}, $type, route($refund ? 'account.refunds.show' : 'author.payouts.show', $r->public_uuid, false), ($refund ? $r->reference : $r->payout_number), $at)];
        }
        if ($source === 'author_application_events') {
            $a = DB::table('author_applications')->where('id', (int) $e->author_application_id)->first();
            $suffix = match ($e->action) {
                'submitted','resubmitted' => 'submitted', 'changes_requested' => 'changes_requested','approved' => 'approved','rejected' => 'rejected',default => null
            };

            return $a && $suffix ? [$this->fact($a->user_id, Type::from('application_'.$suffix), route('author.onboarding.show', [], false), 'application', $at)] : [];
        }
        if ($source === 'product_lifecycle_events') {
            $p = Product::whereKey((int) $e->product_id)->first();
            $suffix = match ($e->action) {
                'submit' => 'submitted','request_changes' => 'changes_requested','approve' => 'approved','reject' => 'rejected','publish' => 'published',default => null
            };
            if (! $p?->owner_id || ! $suffix) {
                return [];
            }
            $revision = $e->product_revision_id ?? null;

            return [$this->fact($p->owner_id, Type::from(($revision ? 'update_' : 'product_').$suffix), ($revision ? route('products.updates.show', [$p, (int) $revision], false) : route('author.products.show', $p, false)), $p->slug, $at)];
        }
        if ($source === 'author_earning_ledger_entries') {
            $suffix = match ($e->type) {
                'earning_pending' => 'recorded','earning_available' => 'available',default => null
            };
            $r = DB::table('author_earnings')->where('id', (int) $e->author_earning_id)->first();

            return $r && $suffix ? [$this->fact($r->author_user_id, Type::from('earning_'.$suffix), route('author.earnings.show', $r->public_uuid, false), 'earning', $at)] : [];
        }
        if ($source === 'product_review_events') {
            $r = ProductReview::with('product')->whereKey((int) $e->review_id)->first();
            if (! $r || ! app(ProductRatingSummary::class)->publicQuery()->whereKey($r->id)->exists()) {
                return [];
            }
            if ($e->type === 'created' && $r->product->owner_id) {
                return [$this->fact($r->product->owner_id, Type::ReviewReceived, route('author.reviews.show', $r, false), 'review', $at)];
            }
            if ($e->type === 'response_created') {
                return [$this->fact($r->customer_user_id, Type::ReviewResponse, route('account.reviews.show', $r, false), 'review', $at)];
            }

            return [];
        }
        if ($source === 'support_ticket_events') {
            $t = SupportTicket::whereKey((int) $e->ticket_id)->first();
            if (! $t) {
                return [];
            }
            $seller = in_array($e->type, ['created', 'customer_replied', 'escalated', 'deescalated']);
            $type = match ($e->type) {
                'created' => Type::SupportCreated,'customer_replied' => Type::SupportCustomerReply,'seller_replied','admin_replied' => Type::SupportSellerReply,'resolved' => Type::SupportResolved,'purchase_refunded' => Type::SupportClosed,'closed' => $e->actor_id !== $t->customer_user_id ? Type::SupportClosed : null,'escalated' => Type::SupportEscalated,'deescalated' => Type::SupportDeescalated,default => null
            };
            $recipient = $seller ? $t->seller_user_id : $t->customer_user_id;
            if (! $type || ! $recipient || $recipient === $e->actor_id) {
                return [];
            }

            return [$this->fact($recipient, $type, route($seller ? 'author.support.show' : 'account.support.show', $t, false), $t->getAttribute('ticket_number'), $at)];
        }

        return [];
    }

    /** @return array<string,mixed> */
    private function fact(int $user, Type $type, string $path, string $reference, string $at): array
    {
        return ['user_id' => $user, 'type' => $type, 'action_path' => $path, 'reference' => $reference, 'occurred_at' => $at];
    }
}
