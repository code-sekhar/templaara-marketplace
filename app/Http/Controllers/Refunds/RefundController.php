<?php

namespace App\Http\Controllers\Refunds;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Refunds\RefundPresentation;
use App\Services\Refunds\RefundWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function create(Request $request, Order $order, RefundPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        abort_unless($admin || $order->user_id === $request->user()->id, 404);
        Inertia::encryptHistory();

        foreach ($order->items as $item) {
            $item->setRelation('order', $order);
        }
        $refunds = $view->items($order->items);

        return Inertia::render('refunds/Request', ['admin' => $admin, 'order' => ['reference' => $order->order_number, 'currency' => $order->currency->value, 'items' => $order->items->map(fn ($i) => [...$i->only(['product_name', 'version', 'line_total']), ...$refunds[$i->id]])->all()]]);
    }

    public function store(Request $request, Order $order, RefundWorkflow $workflow): RedirectResponse
    {
        $admin = $request->routeIs('admin.*');
        $r = $workflow->request($request->user(), $order, $request->all(), $admin);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refund requested for administrator review.']);

        return to_route($admin ? 'admin.refunds.show' : 'account.refunds.show', $r);
    }

    public function index(Request $request, RefundPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        Inertia::encryptHistory();
        $filters = $request->validate(['status' => ['nullable', 'in:requested,approved,processing,completed,failed,cancelled'], 'currency' => ['nullable', 'in:INR,USD,EUR,GBP'], 'search' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'in:duplicate_purchase,product_not_as_described,technical_issue,customer_request,fraud_or_risk,other'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        $q = Refund::with('order:id,order_number,customer_name');
        if (! $admin) {
            $q->where('customer_user_id', $request->user()->id);
        }
        foreach (['status', 'currency'] as $k) {
            if (! empty($filters[$k])) {
                $q->where($k, $filters[$k]);
            }
        }if (! empty($filters['reason'])) {
            $q->where('reason_code', $filters['reason']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $q->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', [$term])->orWhereHas('order', fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$term])->when($admin, fn ($q) => $q->orWhereRaw('LOWER(customer_name) LIKE ?', [$term]))));
        }
        if (! empty($filters['from'])) {
            $q->whereDate('requested_at', '>=', $filters['from']);
        }if (! empty($filters['until'])) {
            $q->whereDate('requested_at', '<=', $filters['until']);
        }

        return Inertia::render('refunds/Index', ['admin' => $admin, 'filters' => $filters, 'report' => $admin ? $view->report() : [], 'refunds' => $q->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($r) => $view->row($r, $admin))]);
    }

    public function show(Request $request, Refund $refund, RefundPresentation $view): Response
    {
        $admin = $request->routeIs('admin.*');
        abort_unless($admin || $refund->customer_user_id === $request->user()->id, 404);
        Inertia::encryptHistory();

        return Inertia::render('refunds/Show', ['admin' => $admin, 'refund' => $view->detail($refund, $admin)]);
    }

    public function transition(Request $request, Refund $refund, RefundWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($request->user(), $refund, $request->route('action'), $request->all(), $request->routeIs('admin.*'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refund status updated.']);

        return back();
    }
}
