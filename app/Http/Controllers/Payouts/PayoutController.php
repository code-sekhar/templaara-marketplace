<?php

namespace App\Http\Controllers\Payouts;

use App\Http\Controllers\Controller;
use App\Models\AuthorPayoutMethod;
use App\Models\PayoutRequest;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutMethods;
use App\Services\Payouts\PayoutMinimums;
use App\Services\Payouts\PayoutPresentation;
use App\Services\Payouts\PayoutWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public function index(Request $request, FinancialBalances $balances, PayoutPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        Inertia::encryptHistory();
        $filters = Validator::make($request->query(), ['status' => ['nullable', 'in:requested,approved,processing,paid,failed,cancelled'], 'currency' => ['nullable', 'in:INR,USD,EUR,GBP'], 'search' => ['nullable', 'string', 'max:100'], 'author' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
        $q = PayoutRequest::query();
        if ($admin) {
            $q->with('author:id,name');
        } else {
            $q->where('author_user_id', $request->user()->id);
        }foreach (['status', 'currency'] as $key) {
            if (! empty($filters[$key])) {
                $q->where($key, $filters[$key]);
            }
        }if (! empty($filters['search'])) {
            $q->where('payout_number', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%');
        }if ($admin && ! empty($filters['author'])) {
            $q->whereHas('author', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['author']).'%'));
        }if (! empty($filters['from'])) {
            $q->whereDate('requested_at', '>=', $filters['from']);
        }if (! empty($filters['until'])) {
            $q->whereDate('requested_at', '<=', $filters['until']);
        }

        return Inertia::render('payouts/Index', ['admin' => $admin, 'filters' => $filters, 'balances' => $balances->all($admin ? null : $request->user()->id), 'report' => $admin ? $view->report() : [], 'payouts' => $q->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($p) => $view->row($p, $admin))]);
    }

    public function create(Request $request, PayoutMethods $methods, FinancialBalances $balances, PayoutMinimums $minimums): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('payouts/Request', ['balances' => $balances->all($request->user()->id), 'minimums' => $minimums->all(), 'methods' => AuthorPayoutMethod::where('author_user_id', $request->user()->id)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->limit(100)->get()->map(fn ($m) => $methods->display($m))]);
    }

    public function store(Request $request, PayoutWorkflow $workflow): RedirectResponse
    {
        $p = $workflow->request($request->user(), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payout requested. Funds are reserved for manual processing.']);

        return to_route('author.payouts.show', $p);
    }

    public function show(Request $request, PayoutRequest $payout, PayoutPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        abort_unless($admin || $payout->author_user_id === $request->user()->id, 404);
        Inertia::encryptHistory();

        return Inertia::render('payouts/Show', ['admin' => $admin, 'payout' => $view->detail($payout, $admin)]);
    }

    public function transition(Request $request, PayoutRequest $payout, PayoutWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($request->user(), $payout, $request->route('action'), $request->all(), $request->routeIs('admin.*'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payout status updated.']);

        return back();
    }

    public function instructions(Request $request, PayoutRequest $payout): JsonResponse
    {
        abort_unless(in_array($payout->status->value, ['approved', 'processing']), 422);

        return response()->json(['instructions' => $payout->getAttribute('method_snapshot_encrypted')])->header('Cache-Control', 'private, no-store');
    }
}
