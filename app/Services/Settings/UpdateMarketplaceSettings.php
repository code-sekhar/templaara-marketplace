<?php

namespace App\Services\Settings;

use App\Events\Settings\MarketplaceSettingChanged;
use App\Models\MarketplaceSetting;
use App\Models\MarketplaceSettingEvent;
use App\Models\User;
use App\Services\Earnings\CommissionPolicies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateMarketplaceSettings
{
    public function __construct(private SettingsRegistry $registry, private MarketplaceSettings $settings) {}

    /** @param array<string,mixed> $input */
    public function save(User $actor, string $group, array $input): void
    {
        Gate::forUser($actor)->authorize('settings.manage');
        $defs = $this->registry->group($group);
        $v = Validator::make($input, ['values' => ['required', 'array:'.implode(',', array_keys($defs)), 'min:1'], 'versions' => ['required', 'array:'.implode(',', array_keys($defs))], 'versions.*' => ['required', 'integer', 'min:0']])->validate();
        if (array_diff(array_keys($input), ['values', 'versions']) || array_keys($v['values']) !== array_keys($v['versions'])) {
            throw ValidationException::withMessages(['values' => 'Send only setting values and their matching versions.']);
        }
        $values = [];
        foreach ($v['values'] as $key => $value) {
            $values[$key] = $this->registry->canonical($group, $key, $value);
        }
        DB::transaction(function () use ($actor, $group, $values, $v): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(753118)');
            }
            $effective = $this->settings->section($group, true);
            $rows = MarketplaceSetting::where('group', $group)->orderBy('key')->lockForUpdate()->get()->keyBy('key');
            foreach ($values as $key => $value) {
                if (($rows->first(fn ($row) => $row->key === $key)->version ?? 0) !== (int) $v['versions'][$key]) {
                    throw ValidationException::withMessages(['values.'.$key => 'This section changed in another session. Reload before saving.']);
                }
            }
            $changed = array_filter($values, fn ($value, $key) => $value !== $effective[$key]['value'], ARRAY_FILTER_USE_BOTH);
            if ($group === 'authors' && $changed) {
                app(CommissionPolicies::class)->configureDefault($actor, ['percentage' => $values['commission_percentage'] ?? $effective['commission_percentage']['value'], 'hold_days' => $values['earning_hold_days'] ?? $effective['earning_hold_days']['value']]);
            }
            foreach ($changed as $key => $value) {
                $r = $rows->first(fn ($row) => $row->key === $key) ?? new MarketplaceSetting;
                $r->forceFill(['group' => $group, 'key' => $key, 'value' => $value, 'value_type' => $this->registry->group($group)[$key]['type'], 'version' => ($r->version ?? 0) + 1, 'updated_by_user_id' => $actor->id]);
                if ($value === null) {
                    $r->setRawAttributes([...$r->getAttributes(), 'value' => 'null']);
                }
                $r->save();
                $at = now();
                (new MarketplaceSettingEvent)->forceFill(['group' => $group, 'key' => $key, 'actor_user_id' => $actor->id, 'actor_name' => $actor->name, 'old_value' => $effective[$key]['value'], 'new_value' => $value, 'occurred_at' => $at])->save();
                MarketplaceSettingChanged::dispatch($group, $key, $actor->id, $effective[$key]['value'], $value, $at->toIso8601String());
            }
        }, 3);
    }
}
