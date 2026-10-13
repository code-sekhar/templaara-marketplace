<?php

namespace App\Services\Earnings;

use App\Models\CommissionPolicy;
use App\Models\Product;
use App\Models\User;
use App\Services\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommissionPolicies
{
    public function lock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(753110)');
        }
    }

    public function effective(CarbonInterface $at): CommissionPolicy
    {
        $this->lock();
        $rows = CommissionPolicy::where('is_default', true)->where('is_active', true)->where('effective_from', '<=', $at)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))->lockForUpdate()->get();
        if ($rows->count() !== 1) {
            throw ValidationException::withMessages(['cart' => 'An effective commission policy is unavailable. Please try checkout later.']);
        }

        return $rows->first();
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): CommissionPolicy
    {
        Gate::forUser($actor)->authorize('commissions.manage');
        $valid = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'percentage' => ['required', 'regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/', 'numeric', 'min:0', 'max:100'], 'hold_days' => ['required', 'integer', 'min:0', 'max:3650'], 'effective_from' => ['required', 'date', 'after_or_equal:now']])->validate();

        return $this->persistDefault($actor, $valid);
    }

    /** Settings changes still create the existing effective-dated domain policy. */
    /** @param array<string,mixed> $data */
    public function configureDefault(User $actor, array $data): CommissionPolicy
    {
        Gate::forUser($actor)->authorize('settings.manage');
        $registry = app(SettingsRegistry::class);
        $data = ['percentage' => $registry->canonical('authors', 'commission_percentage', $data['percentage'] ?? null), 'hold_days' => $registry->canonical('authors', 'earning_hold_days', $data['hold_days'] ?? null)];

        return $this->persistDefault($actor, ['name' => 'Marketplace default', ...$data, 'effective_from' => now()->addSecond()->toDateTimeString()]);
    }

    /** @param array<string,mixed> $valid */
    private function persistDefault(User $actor, array $valid): CommissionPolicy
    {
        return DB::transaction(function () use ($actor, $valid) {
            $this->lock();
            $start = CarbonImmutable::parse($valid['effective_from']);
            $conflict = CommissionPolicy::where('is_active', true)->where('is_default', true)->where('effective_from', '>=', $start)->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['effective_from' => 'A policy is already scheduled at or after this time. Deactivate the future policy before replacing it.']);
            }
            CommissionPolicy::where('is_default', true)->where('is_active', true)->where('effective_from', '<', $start)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $start))->get()->each(fn ($p) => $p->forceFill(['effective_until' => $start])->save());
            $p = new CommissionPolicy;
            $p->forceFill([...$valid, 'effective_from' => $start, 'is_active' => true, 'is_default' => true, 'created_by' => $actor->id])->save();

            return $p;
        }, 3);
    }

    public function deactivate(User $actor, CommissionPolicy $policy): void
    {
        Gate::forUser($actor)->authorize('commissions.manage');
        DB::transaction(function () use ($policy) {
            $this->lock();
            $p = CommissionPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $p->forceFill(['is_active' => false])->save();
        });
    }

    /** @return array<string,mixed> */
    public function snapshot(Product $product, string $gross, CarbonInterface $at): array
    {
        if ($product->origin->value === 'templaara') {
            return ['seller_type' => 'platform', 'commissionable_amount' => $gross];
        }
        if (! $product->owner_id) {
            throw new \LogicException('Author sale requires an owner.');
        }
        $p = $this->effective($at);
        $split = app(CommissionMath::class)->split($gross, $p->getAttribute('percentage'));

        return ['seller_type' => 'author', 'author_user_id' => $product->owner_id, 'author_profile_id' => $product->owner->authorProfile?->id, 'commission_policy_id' => $p->id, 'commission_rate' => $p->getAttribute('percentage'), 'commissionable_amount' => $gross, ...$split, 'earning_hold_days' => $p->getAttribute('hold_days')];
    }
}
