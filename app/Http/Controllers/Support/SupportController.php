<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Services\Products\Files\PrivateArtifactStorage;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Support\SupportEligibility;
use App\Services\Support\SupportPresentation;
use App\Services\Support\SupportWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SupportController extends Controller
{
    private function mode(Request $r): string
    {
        return $r->routeIs('admin.*') ? 'admin' : ($r->routeIs('author.*') ? 'author' : 'customer');
    }

    private function authorize(Request $r, SupportTicket $t, bool $mutate = false): void
    {
        $mode = $this->mode($r);
        if ($mode === 'admin') {
            Gate::authorize($mutate ? 'manage' : 'view', $t);
        } else {
            abort_unless(Gate::allows($mode === 'author' ? 'seller' : 'customer', $t), 404);
        }
    }

    public function create(Request $r, SupportEligibility $eligibility): Response
    {
        $v = $r->validate(['purchase' => ['nullable', 'uuid'], 'product' => ['nullable', 'string', 'max:200']]);
        $q = $eligibility->query($r->user())->with(['order', 'entitlement']);
        if (! empty($v['product'])) {
            $q->whereHas('product', fn ($p) => $p->where('slug', $v['product']));
        }
        if (! empty($v['purchase'])) {
            $q->whereHas('entitlement', fn ($q) => $q->where('public_id', $v['purchase']));
        }$p = $q->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($i) => $eligibility->context($i));

        $limits = app(MarketplaceSettings::class)->supportLimits();

        return Inertia::render('support/Create', ['purchases' => $p, 'requestKey' => (string) Str::uuid(), 'attachmentMaxKb' => $limits['size'], 'attachmentsMax' => $limits['count'], 'attachmentTypes' => array_values($limits['types'])]);
    }

    public function store(Request $r, SupportWorkflow $workflow): RedirectResponse
    {
        $v = $r->validate(['purchase' => ['required', 'uuid'], 'attachments' => ['nullable', 'array'], 'attachments.*' => ['file']]);
        $e = Entitlement::where('public_id', $v['purchase'])->where('user_id', $r->user()->id)->firstOrFail();
        $t = $workflow->create($r->user(), $e->item, $r->all(), $r->file('attachments', []));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Support ticket created.']);

        return to_route('account.support.show', $t);
    }

    public function index(Request $r, SupportPresentation $view): Response
    {
        $mode = $this->mode($r);
        $v = $r->validate(['status' => ['nullable', 'in:awaiting_seller,awaiting_customer,resolved,closed'], 'category' => ['nullable', 'in:installation,configuration,bug,usage_question,compatibility,license_activation,update,other'], 'priority' => ['nullable', 'in:low,normal,high'], 'product' => ['nullable', 'string', 'max:200'], 'search' => ['nullable', 'string', 'max:100'], 'customer' => ['nullable', 'string', 'max:100'], 'seller' => ['nullable', 'string', 'max:100'], 'unread' => ['nullable', 'in:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'escalated' => ['nullable', 'in:1']]);
        $q = SupportTicket::query();
        if ($mode !== 'admin') {
            $q->where($mode === 'author' ? 'seller_user_id' : 'customer_user_id', $r->user()->id);
        }$metrics = (clone $q)->selectRaw("COUNT(CASE WHEN status IN ('awaiting_seller','awaiting_customer') THEN 1 END) AS open,COUNT(CASE WHEN status='awaiting_seller' THEN 1 END) AS awaiting_seller,COUNT(CASE WHEN status='awaiting_customer' THEN 1 END) AS awaiting_customer")->first()->only(['open', 'awaiting_seller', 'awaiting_customer']);
        foreach (['status', 'category', 'priority'] as $k) {
            if (! empty($v[$k])) {
                $q->where($k, $v[$k]);
            }
        }if (! empty($v['product'])) {
            $q->whereHas('product', fn ($q) => $q->where('slug', $v['product']));
        }if (! empty($v['search'])) {
            $term = '%'.addcslashes(mb_strtolower($v['search']), '%_\\').'%';
            $q->where(fn ($q) => $q->whereRaw('LOWER(ticket_number) LIKE ?', [$term])->orWhereRaw('LOWER(subject) LIKE ?', [$term]));
        }
        if ($mode === 'admin') {
            foreach (['customer', 'seller'] as $k) {
                if (! empty($v[$k])) {
                    $q->whereHas($k, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.addcslashes(mb_strtolower($v[$k]), '%_\\').'%']));
                }
            }
        }foreach (['from' => '>=', 'until' => '<='] as $k => $op) {
            if (! empty($v[$k])) {
                $q->whereDate('created_at', $op, $v[$k]);
            }
        }if (! empty($v['escalated'])) {
            $q->where('is_escalated', true);
        }
        $unread = function ($m) use ($r, $mode) {
            $m->where('is_internal', false)->where('sender_user_id', '!=', $r->user()->id);
            if ($mode !== 'admin') {
                $col = 'support_tickets.'.($mode === 'author' ? 'seller_last_read_sequence' : 'customer_last_read_sequence');
                $m->where(fn ($q) => $q->whereColumn('support_messages.sequence', '>', $col));
            } else {
                $m->where('sender_type', 'customer');
            }
        };
        if (! empty($v['unread'])) {
            $q->whereHas('messages', $unread);
        }$records = $q->with(['customer:id,name', 'seller:id,name'])->withCount(['messages as unread_count' => $unread])->orderByDesc('last_message_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($t) => $view->row($t));

        return Inertia::render('support/Index', ['records' => $records, 'filters' => $v, 'mode' => $mode, 'metrics' => $metrics]);
    }

    public function show(Request $r, SupportTicket $ticket, SupportWorkflow $workflow, SupportPresentation $view): Response
    {
        $this->authorize($r, $ticket);
        $mode = $this->mode($r);
        if ($mode !== 'admin') {
            $ticket = $workflow->read($r->user(), $ticket, $mode);
        }Inertia::encryptHistory();

        $limits = app(MarketplaceSettings::class)->supportLimits();

        return Inertia::render('support/Show', ['ticket' => $view->detail($ticket, $r->user(), $mode), 'mode' => $mode, 'requestKey' => (string) Str::uuid(), 'attachmentMaxKb' => $limits['size'], 'attachmentsMax' => $limits['count'], 'attachmentTypes' => array_values($limits['types'])]);
    }

    public function message(Request $r, SupportTicket $ticket, SupportWorkflow $workflow): RedirectResponse
    {
        $this->authorize($r, $ticket, true);
        $r->validate(['attachments' => ['nullable', 'array'], 'attachments.*' => ['file']]);
        $workflow->reply($r->user(), $ticket, $r->all(), $r->file('attachments', []), $this->mode($r), $r->route('action') === 'note');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Support message recorded.']);

        return back();
    }

    public function action(Request $r, SupportTicket $ticket, SupportWorkflow $workflow): RedirectResponse
    {
        $this->authorize($r, $ticket, true);
        $workflow->transition($r->user(), $ticket, $r->route('action'), $r->all(), $this->mode($r));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Support ticket updated.']);

        return back();
    }

    public function download(Request $r, SupportAttachment $attachment): HttpResponse
    {
        $t = $attachment->message->ticket;
        $this->authorize($r, $t);
        abort_if($attachment->message->getAttribute('is_internal') && $this->mode($r) !== 'admin', 404);
        app(PrivateArtifactStorage::class)->assertSecure($attachment->getAttribute('disk'));
        abort_unless(Storage::disk($attachment->getAttribute('disk'))->exists($attachment->getAttribute('path')), 404);

        return Storage::disk($attachment->getAttribute('disk'))->download($attachment->getAttribute('path'), $attachment->getAttribute('original_name'), ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
