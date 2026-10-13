<?php

namespace App\Http\Controllers\Earnings;

use App\Http\Controllers\Controller;
use App\Models\CommissionPolicy;
use App\Services\Earnings\CommissionPolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function index(): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('earnings/Commissions', ['policies' => CommissionPolicy::orderByDesc('effective_from')->paginate(15)->through(fn ($p) => ['id' => $p->id, 'name' => $p->getAttribute('name'), 'percentage' => $p->getAttribute('percentage'), 'hold_days' => $p->getAttribute('hold_days'), 'is_active' => $p->getAttribute('is_active'), 'is_default' => $p->getAttribute('is_default'), 'effective_from' => $p->getAttribute('effective_from')->toIso8601String(), 'effective_until' => $p->getAttribute('effective_until')?->toIso8601String()])]);
    }

    public function store(Request $request, CommissionPolicies $service): RedirectResponse
    {
        $service->create($request->user(), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'New commission policy scheduled.']);

        return back();
    }

    public function deactivate(Request $request, CommissionPolicy $policy, CommissionPolicies $service): RedirectResponse
    {
        $service->deactivate($request->user(), $policy);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Policy deactivated. Author checkout requires an effective active policy.']);

        return back();
    }
}
