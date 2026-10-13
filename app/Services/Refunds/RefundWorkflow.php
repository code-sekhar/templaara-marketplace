<?php

namespace App\Services\Refunds;

use App\Enums\EntitlementStatus;
use App\Enums\LicenseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Events\Refunds\RefundApproved;
use App\Events\Refunds\RefundCancelled;
use App\Events\Refunds\RefundCompleted;
use App\Events\Refunds\RefundFailed;
use App\Events\Refunds\RefundProcessing;
use App\Events\Refunds\RefundRequested;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutRequest;
use App\Models\Refund;
use App\Models\RefundCompensation;
use App\Models\RefundEvent;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\Commerce\Money;
use App\Services\Reviews\ReviewWorkflow;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Support\SupportWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundWorkflow
{
    /** @param array<string,mixed> $input */
    public function request(User $actor, Order $target, array $input, bool $admin = false): Refund
    {
        if ($admin) {
            Gate::forUser($actor)->authorize('refunds.manage');
        } else {
            abort_unless($target->user_id === $actor->id, 404);
        }
        $v = Validator::make($input, ['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'integer', 'distinct'], 'reason_code' => ['required', Rule::enum(RefundReason::class)], 'customer_reason' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();

        return $this->atomic($actor, function () use ($actor, $target, $v) {
            app(MarketplaceSettings::class)->lock();
            $o = $this->lock($target);
            $this->paid($o);
            $old = Refund::where('requested_by', $actor->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                $ids = $old->items()->pluck('order_item_id')->sort()->values()->all();
                $requested = array_map('intval', $v['items']);
                sort($requested);
                if ($old->order_id !== $o->id || $ids !== $requested || $old->getAttribute('reason_code') !== $v['reason_code'] || $old->getAttribute('customer_reason') !== ($v['customer_reason'] ?? null)) {
                    $this->error('refund', 'This request key has different refund terms.');
                }

                return $old;
            }
            $days = app(RefundWindow::class)->forOrder($o);
            $ends = $o->getAttribute('paid_at')->addDays($days);
            if (now()->greaterThan($ends)) {
                $this->error('refund', 'This purchase is outside the configured refund request window.');
            }
            $items = $o->items()->whereIn('id', $v['items'])->with(['earning', 'entitlement' => fn ($q) => $q->withExists(['accesses', 'activations']), 'entitlement.license'])->orderBy('id')->lockForUpdate()->get();
            if ($items->count() !== count($v['items'])) {
                $this->error('items', 'Select purchased items from this order.');
            }
            foreach ($items as $i) {
                $state = DB::table('purchase_refund_states')->where('order_item_id', $i->id)->first();
                if ($state && ($state->active_refund_id || $state->completed_refund_item_id)) {
                    $this->error('items', 'A selected item already has an active or completed refund.');
                }
                if (BigDecimal::of($i->line_total)->isLessThanOrEqualTo(0)) {
                    $this->error('items', 'Only positive paid items can be refunded.');
                }
                if (! in_array($i->getAttribute('seller_type'), ['author', 'platform'], true) || ($i->getAttribute('seller_type') === 'author' && ! $i->earning) || ! $i->entitlement?->license) {
                    $this->error('refund', 'Purchase access or financial records need reconciliation before a refund.');
                }
            }
            $at = now();
            $r = new Refund;
            $r->forceFill(['public_uuid' => (string) Str::uuid(), 'reference' => 'TMP-REF-'.now()->year.'-'.Str::ulid(), 'request_key' => $v['request_key'], 'order_id' => $o->id, 'customer_user_id' => $o->user_id, 'currency' => $o->currency->value, 'amount' => Money::sum($items->map(fn ($item) => $item->getAttribute('tax_snapshot')['grand_total'] ?? $item->line_total)), 'status' => RefundStatus::Requested, 'reason_code' => $v['reason_code'], 'customer_reason' => $v['customer_reason'] ?? null, 'requested_by' => $actor->id, 'requested_at' => $at, 'window_days' => $days, 'window_ends_at' => $ends])->save();
            foreach ($items as $i) {
                $ri = new RefundItem;
                $ri->forceFill(['refund_id' => $r->id, 'order_item_id' => $i->id, 'author_earning_id' => $i->earning?->id, 'amount' => $i->getAttribute('tax_snapshot')['grand_total'] ?? $i->line_total, 'commission_amount' => $i->getAttribute('commission_amount') ?? '0.00', 'author_amount' => $i->getAttribute('author_amount') ?? '0.00', 'review_facts' => ['download_access_issued' => (bool) $i->entitlement->getAttribute('accesses_exists'), 'activation_exists' => (bool) $i->entitlement->getAttribute('activations_exists'), 'license_state' => $i->entitlement->license->status->value], 'created_at' => $at])->save();
                DB::table('purchase_refund_states')->updateOrInsert(['order_item_id' => $i->id], ['active_refund_id' => $r->id, 'completed_refund_item_id' => null]);
            }
            $this->audit($r, $actor, null, null);
            RefundRequested::dispatch($r->id);

            return $r;
        });
    }

    /** @param array<string,mixed> $input */
    public function transition(User $actor, Refund $target, string $action, array $input = [], bool $admin = false): Refund
    {
        if ($action === 'cancel' && ! $admin) {
            abort_unless($target->customer_user_id === $actor->id, 404);
        } else {
            Gate::forUser($actor)->authorize('refunds.manage');
        }
        foreach (['external_reference', 'reason', 'admin_note'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = trim($input[$k]);
            }
        }
        $v = Validator::make($input, ['external_reference' => [$action === 'complete' ? 'required' : 'nullable', 'string', 'min:3', 'max:255'], 'reason' => [($action === 'fail' || ($action === 'cancel' && $admin)) ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'], 'admin_note' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->atomic($actor, function () use ($actor, $target, $action, $v, $admin) {
            $o = $this->lock($target->order);
            $r = Refund::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $from = $r->status;
            if ($action === 'complete' && $from === RefundStatus::Completed) {
                if ($r->getAttribute('external_reference') !== $v['external_reference']) {
                    $this->error('refund', 'Completion already records a different settlement reference.');
                }

                return $r;
            }
            $allowed = match ($action) {
                'approve' => $from === RefundStatus::Requested,'process' => $from === RefundStatus::Approved,'complete','fail' => $from === RefundStatus::Processing,'cancel' => $from === RefundStatus::Requested || ($admin && $from === RefundStatus::Approved),default => false
            };
            if (! $allowed) {
                $this->error('refund', 'This refund changed or this action is unavailable. Refresh its status.');
            }
            if (in_array($action, ['process', 'complete'])) {
                $this->conflicts($r);
            }
            if ($action === 'complete') {
                $this->paid($o);
            }
            $state = match ($action) {
                'approve' => RefundStatus::Approved,'process' => RefundStatus::Processing,'complete' => RefundStatus::Completed,'fail' => RefundStatus::Failed,'cancel' => RefundStatus::Cancelled, default => $this->error('refund', 'Unknown refund action.')
            };
            $prefix = match ($action) {
                'approve' => 'reviewed','process' => 'processing','complete' => 'completed','fail' => 'failed','cancel' => 'cancelled'
            };
            $fields = ['status' => $state, $prefix.'_by' => $actor->id, $prefix.'_at' => now()];
            $reason = $v['reason'] ?? ($action === 'cancel' ? 'Cancelled by customer.' : null);
            if ($admin && isset($v['admin_note'])) {
                $fields['admin_note'] = $v['admin_note'];
            }if ($action === 'complete') {
                $fields['external_reference'] = $v['external_reference'];
            }if ($action === 'fail') {
                $fields['failure_reason'] = $reason;
            }if ($action === 'cancel') {
                $fields['cancellation_reason'] = $reason;
            }
            $r->forceFill($fields)->save();
            foreach ($r->items()->orderBy('order_item_id')->get() as $i) {
                if ($action === 'complete') {
                    $this->compensate($r, $i);
                    DB::table('purchase_refund_states')->where('order_item_id', $i->getAttribute('order_item_id'))->update(['active_refund_id' => null, 'completed_refund_item_id' => $i->id]);
                } elseif (in_array($action, ['fail', 'cancel'])) {
                    DB::table('purchase_refund_states')->where('order_item_id', $i->getAttribute('order_item_id'))->where('active_refund_id', $r->id)->update(['active_refund_id' => null]);
                }
            }
            if ($action === 'complete') {
                foreach ($r->items()->get() as $item) {
                    app(ReviewWorkflow::class)->reconcile($r->customer_user_id, OrderItem::whereKey($item->getAttribute('order_item_id'))->firstOrFail()->product_id);
                    app(SupportWorkflow::class)->refunded($item->getAttribute('order_item_id'));
                }
            }
            $this->audit($r, $actor, $from->value, $reason);
            $event = match ($action) {
                'approve' => RefundApproved::class,'process' => RefundProcessing::class,'complete' => RefundCompleted::class,'fail' => RefundFailed::class,'cancel' => RefundCancelled::class
            };
            $event::dispatch($r->id);

            return $r;
        });
    }

    public function conflicts(Refund $r): void
    {
        $authors = AuthorEarning::whereIn('id', $r->items()->whereNotNull('author_earning_id')->pluck('author_earning_id'))->pluck('author_user_id');
        if (PayoutRequest::whereIn('author_user_id', $authors)->where('currency', $r->getAttribute('currency'))->whereIn('status', ['requested', 'approved', 'processing'])->exists()) {
            $this->error('refund', 'Resolve unpaid payouts for the affected authors in this currency before processing or completing the refund. Cancel requested/approved payouts or resolve external processing first.');
        }
    }

    private function compensate(Refund $r, RefundItem $i): void
    {
        $item = OrderItem::whereKey($i->getAttribute('order_item_id'))->firstOrFail();
        $e = $i->getAttribute('author_earning_id') ? AuthorEarning::whereKey($i->getAttribute('author_earning_id'))->lockForUpdate()->firstOrFail() : null;
        $c = new RefundCompensation;
        $c->forceFill(['refund_item_id' => $i->id, 'order_item_id' => $item->id, 'author_earning_id' => $e?->id, 'author_user_id' => $e?->author_user_id, 'currency' => $r->getAttribute('currency'), 'gross_amount' => $item->line_total, 'commission_amount' => $i->getAttribute('commission_amount'), 'author_amount' => $i->getAttribute('author_amount'), 'earning_bucket' => $e?->status->value, 'paid_exposure' => $e ? Money::decimal((string) $e->ledger()->where('type', 'payout_paid')->sum('amount')) : '0.00', 'created_at' => $r->getAttribute('completed_at')])->save();
        if ($e) {
            (new AuthorEarningLedgerEntry)->forceFill(['author_earning_id' => $e->id, 'author_user_id' => $e->author_user_id, 'currency' => $r->getAttribute('currency'), 'type' => 'refund_reversal', 'amount' => $i->getAttribute('author_amount'), 'refund_item_id' => $i->id, 'occurred_at' => $r->getAttribute('completed_at')])->save();
        }
        $ent = Entitlement::where('order_item_id', $item->id)->lockForUpdate()->firstOrFail();
        $license = License::where('entitlement_id', $ent->id)->lockForUpdate()->firstOrFail();
        if ($ent->status !== EntitlementStatus::Revoked) {
            $ent->forceFill(['status' => EntitlementStatus::Revoked, 'revoked_at' => $r->getAttribute('completed_at')])->save();
        }
        if ($license->status !== LicenseStatus::Revoked) {
            $license->forceFill(['status' => LicenseStatus::Revoked, 'revoked_at' => $r->getAttribute('completed_at')])->save();
        }
    }

    private function lock(Order $target): Order
    {
        $ids = $target->items()->whereNotNull('author_user_id')->pluck('author_user_id')->push($target->user_id)->unique()->sort()->values();
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        return Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
    }

    private function paid(Order $o): void
    {
        if ($o->status !== OrderStatus::Completed || $o->payment_status !== PaymentStatus::Paid || ! $o->payments()->whereKey($o->getAttribute('settled_payment_id'))->where('status', 'paid')->where('amount', $o->grand_total)->where('currency', $o->currency->value)->exists()) {
            $this->error('refund', 'Only verified paid purchases can be refunded.');
        }
    }

    private function audit(Refund $r, User $actor, ?string $from, ?string $reason): void
    {
        $prefix = match ($r->status) {
            RefundStatus::Requested => 'requested',RefundStatus::Approved => 'reviewed',RefundStatus::Processing => 'processing',RefundStatus::Completed => 'completed',RefundStatus::Failed => 'failed',RefundStatus::Cancelled => 'cancelled'
        };
        (new RefundEvent)->forceFill(['refund_id' => $r->id, 'actor_id' => $actor->id, 'previous_status' => $from, 'status' => $r->status->value, 'reason' => $reason, 'occurred_at' => $r->getAttribute($prefix.'_at')])->save();
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param callable():Refund $work */
    private function atomic(User $actor, callable $work): Refund
    {
        try {
            return DB::transaction(fn () => $work(), 3);
        } catch (QueryException $e) {
            Log::error('Refund requires operational review.', ['actor_id' => $actor->id, 'exception' => $e::class]);
            $this->error('refund', 'Refund could not be recorded safely. Refresh and retry or contact Templaara.');
        }
    }
}
