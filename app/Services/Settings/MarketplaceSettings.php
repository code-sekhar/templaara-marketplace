<?php

namespace App\Services\Settings;

use App\Models\CommissionPolicy;
use App\Models\MarketplaceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MarketplaceSettings
{
    public function __construct(private SettingsRegistry $registry) {}

    /** Shared settings lock must precede domain locks; no cross-request/process cache. */
    public function lock(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Settings coherence requires a transaction.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock_shared(753118)');
        }
    }

    public function fallback(string $group, string $key): mixed
    {
        $def = $this->registry->group($group)[$key];
        if ($group === 'authors') {
            $p = CommissionPolicy::where('is_default', true)->where('is_active', true)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))->orderByDesc('effective_from')->first();

            return $p?->getAttribute($key === 'commission_percentage' ? 'percentage' : 'hold_days') ?? $def['default'];
        }
        $v = $def['config'] ? config($def['config']) : null;
        if ($v === null || $v === '') {
            if ($group === 'refunds' && app()->environment('local', 'testing')) {
                $v = config('refunds.development_window_days');
            }
            if ($group === 'payouts' && app()->environment('local', 'testing')) {
                $v = config('payouts.development_minimums.'.substr($key, 8));
            }
        }

        return ($v === null || $v === '') ? $def['default'] : $v;
    }

    /** @return array<string,array<string,mixed>> */
    public function section(string $group, bool $allowUnavailable = false): array
    {
        $defs = $this->registry->group($group);
        $rows = Schema::hasTable('marketplace_settings') ? MarketplaceSetting::where('group', $group)->get()->keyBy('key') : collect();
        $out = [];
        foreach ($defs as $key => $def) {
            $row = $rows->first(fn ($row) => $row->key === $key);
            $fallback = $this->fallback($group, $key);
            // CommissionPolicy remains authoritative, including changes made through its existing workspace.
            $v = $group === 'authors' ? $fallback : ($row->value ?? $fallback);
            try {
                $value = $this->registry->canonical($group, $key, $v);
            } catch (ValidationException) {
                $value = null;
                if (! ($row && in_array($key, ['enabled_currencies', 'allowed_types'], true))) {
                    try {
                        $value = $this->registry->canonical($group, $key, $fallback);
                    } catch (ValidationException) { /* Fail closed; the admin can supply an explicit valid policy. */
                    }
                }
                if ($value === null && ! $allowUnavailable) {
                    throw ValidationException::withMessages(['policy' => 'Marketplace policy is unavailable. Contact Templaara.']);
                }
            }
            $out[$key] = ['value' => $value, 'version' => $row->version ?? 0, 'type' => $def['type'], 'description' => $def['description'], 'snapshot' => $def['snapshot'], 'editable' => $def['editable'], 'reload' => $def['reload']];
        }

        return $out;
    }

    public function value(string $group, string $key): mixed
    {
        $value = $this->section($group, true)[$key]['value'];
        if ($value === null) {
            throw ValidationException::withMessages(['policy' => 'Marketplace policy is unavailable. Contact Templaara.']);
        }

        return $value;
    }

    /** @return array<int,string> */
    public function currencies(): array
    {
        return $this->value('commerce', 'enabled_currencies');
    }

    public function cartMaximum(): int
    {
        return $this->value('commerce', 'maximum_cart_items');
    }

    public function refundDays(): int
    {
        return $this->value('refunds', 'refund_window_days');
    }

    public function payoutMinimum(string $currency): string
    {
        if (! in_array($currency, SettingsRegistry::CURRENCIES, true)) {
            throw new \LogicException('Unsupported payout currency.');
        }

        return $this->value('payouts', 'minimum_'.$currency);
    }

    public function productLimit(string $key): int
    {
        try {
            return min(SettingsRegistry::PRODUCT_CEILINGS[$key], $this->value('products', $key));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['file' => 'Upload policy is unavailable. Contact Templaara.']);
        }
    }

    /** @return array{size:int,count:int,types:array<int,string>} */
    public function supportLimits(): array
    {
        $s = $this->section('support');

        return ['size' => min(10240, $s['attachment_max_kb']['value']), 'count' => min(5, $s['attachments_max']['value']), 'types' => array_intersect(SettingsRegistry::SUPPORT_TYPES, $s['allowed_types']['value'])];
    }

    public function timezone(): string
    {
        return $this->value('reporting', 'timezone');
    }

    /** @return array<string,mixed> */
    public function identity(): array
    {
        return array_map(fn ($r) => $r['value'], $this->section('general'));
    }

    public function sender(bool $support = false): string
    {
        return $this->value('notifications', $support ? 'support_sender_name' : 'sender_name');
    }
}
