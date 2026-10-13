<?php

namespace App\Services\Payouts;

use App\Enums\PayoutStatus;
use App\Events\Payouts\PayoutApproved;
use App\Events\Payouts\PayoutCancelled;
use App\Events\Payouts\PayoutFailed;
use App\Events\Payouts\PayoutPaid;
use App\Events\Payouts\PayoutProcessing;
use App\Events\Payouts\PayoutRequested;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\AuthorPayoutMethod;
use App\Models\PayoutAllocation;
use App\Models\PayoutEvent;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Commerce\Money;
use App\Services\Settings\MarketplaceSettings;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayoutWorkflow
{
    /** @param array<string,mixed> $input */
    public function request(User $actor, array $input): PayoutRequest
    {
        abort_unless($actor->hasRole('author'), 403);
        $data = Validator::make($input, ['currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP'])], 'method' => ['required', 'uuid'], 'amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'], 'request_key' => ['required', 'uuid']])->validate();
        $amount = Money::decimal($data['amount']);
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            $this->error('amount', 'Request a positive payout amount.');
        }
        try {
            return DB::transaction(function () use ($actor, $data, $amount) {
                app(MarketplaceSettings::class)->lock();
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $old = PayoutRequest::where('author_user_id', $actor->id)->where('request_key', $data['request_key'])->first();
                if ($old) {
                    $m = AuthorPayoutMethod::whereKey($old->getAttribute('payout_method_id'))->first();
                    if ($old->amount !== $amount || $old->currency !== $data['currency'] || $m?->getAttribute('public_uuid') !== $data['method']) {
                        $this->error('payout', 'This request key already belongs to different payout terms.');
                    }

                    return $old;
                }
                $method = AuthorPayoutMethod::where('public_uuid', $data['method'])->where('author_user_id', $actor->id)->lockForUpdate()->first();
                if (! $method || $method->getAttribute('status') !== 'active' || $method->getAttribute('currency') !== $data['currency']) {
                    $this->error('method', 'Choose your active payout method in the same currency.');
                }
                $minimum = app(PayoutMinimums::class)->forCurrency($data['currency']);
                if (BigDecimal::of($amount)->isLessThan($minimum)) {
                    $this->error('amount', 'The minimum payout is '.$minimum.' '.$data['currency'].'.');
                }
                if (BigDecimal::of($amount)->isGreaterThan(app(FinancialBalances::class)->available($actor->id, $data['currency']))) {
                    $this->error('amount', 'The amount exceeds your available earnings. Pending and reserved earnings cannot fund a payout.');
                }
                $now = now();
                $p = new PayoutRequest;
                $p->forceFill(['public_uuid' => (string) Str::uuid(), 'payout_number' => 'TMP-PAY-'.now()->year.'-'.Str::ulid(), 'request_key' => $data['request_key'], 'author_user_id' => $actor->id, 'author_profile_id' => $method->getAttribute('author_profile_id'), 'payout_method_id' => $method->id, 'currency' => $data['currency'], 'amount' => $amount, 'status' => PayoutStatus::Requested, 'method_type' => $method->getAttribute('type'), 'method_snapshot_encrypted' => $method->getAttribute('details_encrypted'), 'method_display_snapshot' => app(PayoutMethods::class)->display($method), 'requested_at' => $now])->save();
                $remaining = BigDecimal::of($amount);
                $after = null;
                while ($remaining->isPositive()) {
                    $q = AuthorEarning::where('author_user_id', $actor->id)->where('currency', $data['currency'])->where('status', 'available')->orderBy('available_at')->orderBy('id');
                    if ($after) {
                        $q->where(fn ($q) => $q->where('available_at', '>', $after['at'])->orWhere(fn ($q) => $q->where('available_at', $after['at'])->where('id', '>', $after['id'])));
                    }
                    $rows = $q->lockForUpdate()->limit(100)->get();
                    if ($rows->isEmpty()) {
                        $this->error('amount', 'Available earnings changed. Please retry.');
                    }
                    foreach ($rows as $e) {
                        $used = AuthorEarningLedgerEntry::where('author_earning_id', $e->id)->selectRaw("COALESCE(SUM(CASE WHEN type='payout_reserved' THEN amount WHEN type IN ('payout_released') THEN -amount WHEN type='refund_reversal' THEN amount ELSE 0 END),0) AS used")->first()->getAttribute('used');
                        $capacity = BigDecimal::of($e->getAttribute('author_amount'))->minus((string) $used);
                        $after = ['at' => $e->getAttribute('available_at'), 'id' => $e->id];
                        if (! $capacity->isPositive()) {
                            continue;
                        }$part = $capacity->isLessThan($remaining) ? $capacity : $remaining;
                        $allocation = new PayoutAllocation;
                        $allocation->forceFill(['payout_request_id' => $p->id, 'author_earning_id' => $e->id, 'amount' => Money::decimal((string) $part), 'created_at' => $now])->save();
                        $this->movement($p, $allocation, 'payout_reserved', $now);
                        $remaining = $remaining->minus($part);
                        if (! $remaining->isPositive()) {
                            break;
                        }
                    }
                }
                $this->audit($p, $actor, null, null, $now);
                PayoutRequested::dispatch($p->id);

                return $p;
            }, 3);
        } catch (QueryException $e) {
            $this->databaseError($actor, $e);
        }
    }

    /** @param array<string,mixed> $input */
    public function transition(User $actor, PayoutRequest $target, string $action, array $input = [], bool $asAdmin = false): PayoutRequest
    {
        if ($action === 'cancel' && ! $asAdmin) {
            abort_unless($actor->hasRole('author'), 403);
            abort_unless($target->author_user_id === $actor->id, 404);
        } else {
            Gate::forUser($actor)->authorize('payouts.manage');
        }
        $valid = Validator::make($input, ['external_reference' => [$action === 'pay' ? 'required' : 'nullable', 'string', 'min:3', 'max:255'], 'reason' => [(in_array($action, ['fail', 'cancel']) && ($asAdmin || $action === 'fail')) ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'], 'admin_note' => ['nullable', 'string', 'max:2000']])->validate();
        try {
            return DB::transaction(function () use ($actor, $target, $action, $valid, $asAdmin) {
                User::whereKey($target->author_user_id)->lockForUpdate()->firstOrFail();
                $p = PayoutRequest::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $from = $p->status;
                $next = match ($action) {
                    'approve' => PayoutStatus::Approved,'process' => PayoutStatus::Processing,'pay' => PayoutStatus::Paid,'fail' => PayoutStatus::Failed,'cancel' => PayoutStatus::Cancelled,default => null
                };
                $allowed = match ($action) {
                    'approve' => $from === PayoutStatus::Requested,'process' => $from === PayoutStatus::Approved,'pay','fail' => $from === PayoutStatus::Processing,'cancel' => $from === PayoutStatus::Requested || ($asAdmin && $from === PayoutStatus::Approved),default => false
                };
                if (! $allowed || ! $next) {
                    $this->error('payout', 'This payout changed or the requested transition is unavailable. Refresh its status.');
                }
                $allocations = $p->allocations()->orderBy('id')->lockForUpdate()->get();
                if (Money::sum($allocations->map(fn ($a) => $a->getAttribute('amount'))) !== $p->amount) {
                    $this->error('payout', 'Payout allocation requires operational review.');
                }foreach ($allocations as $a) {
                    if (! AuthorEarningLedgerEntry::where('payout_allocation_id', $a->id)->where('type', 'payout_reserved')->exists()) {
                        $this->error('payout', 'Payout reservation requires operational review.');
                    }
                }
                $now = now();
                $prefix = match ($action) {
                    'approve' => 'reviewed','process' => 'processing','pay' => 'paid','fail' => 'failed','cancel' => 'cancelled'
                };
                $fields = ['status' => $next, $prefix.'_at' => $now, $prefix.'_by' => $actor->id];
                if ($asAdmin && isset($valid['admin_note'])) {
                    $fields['admin_note'] = $valid['admin_note'];
                }if ($action === 'pay') {
                    $fields['external_reference'] = $valid['external_reference'];
                }$reason = $valid['reason'] ?? ($action === 'cancel' ? 'Cancelled by author.' : null);
                if ($action === 'fail') {
                    $fields['failure_reason'] = $reason;
                }if ($action === 'cancel') {
                    $fields['cancellation_reason'] = $reason;
                }$p->forceFill($fields)->save();
                if (in_array($action, ['pay', 'fail', 'cancel'])) {
                    foreach ($allocations as $a) {
                        $this->movement($p, $a, $action === 'pay' ? 'payout_paid' : 'payout_released', $now);
                    }
                }
                $this->audit($p, $actor, $from->value, $reason, $now);
                $event = match ($action) {
                    'approve' => PayoutApproved::class,'process' => PayoutProcessing::class,'pay' => PayoutPaid::class,'fail' => PayoutFailed::class,'cancel' => PayoutCancelled::class
                };
                $event::dispatch($p->id);

                return $p;
            }, 3);
        } catch (QueryException $e) {
            $this->databaseError($actor, $e);
        }
    }

    private function movement(PayoutRequest $p, PayoutAllocation $a, string $type, CarbonInterface $at): void
    {
        (new AuthorEarningLedgerEntry)->forceFill(['author_earning_id' => $a->getAttribute('author_earning_id'), 'author_user_id' => $p->author_user_id, 'payout_allocation_id' => $a->id, 'currency' => $p->currency, 'type' => $type, 'amount' => $a->getAttribute('amount'), 'occurred_at' => $at])->save();
    }

    private function audit(PayoutRequest $p, User $actor, ?string $from, ?string $reason, CarbonInterface $at): void
    {
        (new PayoutEvent)->forceFill(['payout_request_id' => $p->id, 'actor_id' => $actor->id, 'previous_status' => $from, 'status' => $p->status->value, 'reason' => $reason, 'occurred_at' => $at])->save();
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function databaseError(User $actor, QueryException $e): never
    {
        Log::error('Payout accounting operation requires retry.', ['author_id' => $actor->id, 'exception' => $e::class]);
        $this->error('payout', 'Unable to complete this payout operation. Refresh and retry, or contact Templaara.');
    }
}
