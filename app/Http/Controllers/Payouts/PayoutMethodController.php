<?php

namespace App\Http\Controllers\Payouts;

use App\Http\Controllers\Controller;
use App\Models\AuthorPayoutMethod;
use App\Services\Payouts\PayoutMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutMethodController extends Controller
{
    public function index(Request $request, PayoutMethods $service): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('payouts/Methods', ['methods' => AuthorPayoutMethod::where('author_user_id', $request->user()->id)->orderByDesc('id')->paginate(15)->through(fn ($m) => $service->display($m))]);
    }

    public function create(): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('payouts/MethodForm', ['method' => null]);
    }

    public function edit(Request $request, AuthorPayoutMethod $method, PayoutMethods $service): Response
    {
        abort_unless($method->author_user_id === $request->user()->id, 404);
        Inertia::encryptHistory();

        return Inertia::render('payouts/MethodForm', ['method' => $service->display($method)]);
    }

    public function store(Request $request, PayoutMethods $service): RedirectResponse
    {
        $service->save($request->user(), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Manual bank transfer instructions saved.']);

        return to_route('author.payout-methods.index');
    }

    public function update(Request $request, AuthorPayoutMethod $method, PayoutMethods $service): RedirectResponse
    {
        $service->save($request->user(), $request->all(), $method);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Instructions updated for future payout requests.']);

        return to_route('author.payout-methods.index');
    }

    public function disable(Request $request, AuthorPayoutMethod $method, PayoutMethods $service): RedirectResponse
    {
        $service->disable($request->user(), $method);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payout method disabled. Existing requests retain their destination.']);

        return back();
    }
}
