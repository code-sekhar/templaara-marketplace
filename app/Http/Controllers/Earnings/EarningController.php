<?php

namespace App\Http\Controllers\Earnings;

use App\Http\Controllers\Controller;
use App\Models\AuthorEarning;
use App\Services\Earnings\EarningPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class EarningController extends Controller
{
    public function index(Request $request, EarningPresentation $view): Response
    {
        Inertia::encryptHistory();
        $admin = $request->routeIs('admin.*');
        $filters = Validator::make($request->query(), ['search' => ['nullable', 'string', 'max:100'], 'currency' => ['nullable', 'in:INR,USD,EUR,GBP'], 'status' => ['nullable', 'in:pending,available']])->validate();
        $q = AuthorEarning::with(['item', 'order:id,order_number'])->withSum(['ledger as refund_adjustment' => fn ($q) => $q->where('type', 'refund_reversal')], 'amount');
        if ($admin) {
            $q->with('author:id,name');
        } else {
            $q->where('author_user_id', $request->user()->id);
        }
        if (! empty($filters['currency'])) {
            $q->where('currency', $filters['currency']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $q->whereHas('item', fn ($q) => $q->where('product_name', 'like', $term));
        }

        return Inertia::render('earnings/Index', ['admin' => $admin, 'filters' => $filters, 'balances' => ($admin ? $view->report() : $view->balances($request->user()->id)), 'earnings' => $q->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($e) => $view->row($e, $admin))]);
    }

    public function show(Request $request, AuthorEarning $earning, EarningPresentation $view): Response
    {
        Inertia::encryptHistory();
        $admin = $request->routeIs('admin.*');
        abort_unless($admin || $earning->author_user_id === $request->user()->id, 404);
        $earning->loadSum(['ledger as refund_adjustment' => fn ($q) => $q->where('type', 'refund_reversal')], 'amount');
        $earning->load(['item', 'order:id,order_number', 'author:id,name']);

        return Inertia::render('earnings/Show', ['admin' => $admin, 'earning' => $view->row($earning, $admin), 'ledger' => $earning->ledger()->orderBy('id')->get(['type', 'amount', 'currency', 'occurred_at'])->map(fn ($r) => ['type' => $r->getAttribute('type'), 'amount' => $r->getAttribute('amount'), 'currency' => $r->getAttribute('currency'), 'occurred_at' => $r->getAttribute('occurred_at')])]);
    }
}
