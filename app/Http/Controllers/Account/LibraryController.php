<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\LicenseActivation;
use App\Services\Entitlements\EntitlementDownload;
use App\Services\Entitlements\LibraryPresentation;
use App\Services\Entitlements\LicenseActivationService;
use App\Services\Refunds\RefundPresentation;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Support\SupportPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function index(Request $request, LibraryPresentation $presenter): Response
    {
        $admin = $request->routeIs('admin.entitlements.*');
        $query = $presenter->query();
        if (! $admin) {
            $query->where('user_id', $request->user()->id);
        }

        $records = $query->orderByDesc('granted_at')->orderByDesc('id')->paginate(15)->withQueryString();
        $refunds = app(RefundPresentation::class)->items($records->getCollection()->pluck('item'));

        $reviewEntries = app(ReviewPresentation::class)->entries($request->user(), $records->getCollection()->pluck('item.product')->unique('id')->values());

        $supportEntries = app(SupportPresentation::class)->entries($request->user(), $records->getCollection()->pluck('item'));

        return Inertia::render('library/Index', ['admin' => $admin, 'records' => $records->through(fn ($e) => $presenter->summary($e, $admin, $refunds[$e->item->id], $reviewEntries[$e->product_id], $supportEntries[$e->item->id]))]);
    }

    public function show(Entitlement $entitlement, LibraryPresentation $presenter): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('view', $entitlement);
        $e = $presenter->query()->whereKey($entitlement->id)->firstOrFail();

        Inertia::encryptHistory();
        $response = Inertia::render('library/Show', ['purchase' => $presenter->detail($e)])->toResponse(request());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    public function deactivate(Request $request, Entitlement $entitlement, LicenseActivation $activation, LicenseActivationService $service): RedirectResponse
    {
        $service->ownerDeactivate($request->user(), $entitlement, $activation);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Installation deactivated.']);

        return back();
    }

    public function download(Request $request, Entitlement $entitlement, EntitlementDownload $downloads): \Symfony\Component\HttpFoundation\Response
    {
        return $downloads->deliver($request->user(), $entitlement);
    }
}
