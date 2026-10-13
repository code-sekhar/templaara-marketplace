<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceSettingEvent;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Settings\SettingsRegistry;
use App\Services\Settings\UpdateMarketplaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceSettingsController extends Controller
{
    public function index(MarketplaceSettings $settings, SettingsRegistry $registry): Response
    {
        $sections = [];
        foreach (array_keys($registry->definitions()) as $group) {
            $sections[$group] = $settings->section($group, true);
        }

        return Inertia::render('marketplace-settings/Index', ['sections' => $sections, 'history' => MarketplaceSettingEvent::orderByDesc('id')->limit(30)->get()->map(fn ($e) => $e->only(['group', 'key', 'actor_name', 'old_value', 'new_value', 'occurred_at']))]);
    }

    public function update(Request $request, string $group, UpdateMarketplaceSettings $settings): RedirectResponse
    {
        $settings->save($request->user(), $group, $request->all());

        return back()->with('success', 'Marketplace settings saved.');
    }
}
