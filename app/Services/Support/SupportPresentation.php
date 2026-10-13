<?php

namespace App\Services\Support;

use App\Models\OrderItem;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Collection;

class SupportPresentation
{
    public function name(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $safe = ($parts[0] ?? 'Customer').(count($parts) > 1 ? ' '.mb_substr(end($parts), 0, 1).'.' : '');

        return str_contains($safe, '@') ? 'Customer' : mb_substr($safe, 0, 100);
    }

    /**
     * @return array<string,mixed> */
    public function row(SupportTicket $t): array
    {
        return [...$t->only(['public_uuid', 'ticket_number', 'subject', 'product_name', 'version', 'offer_name', 'license_type', 'support_period_days', 'paid_at', 'support_ends_at', 'last_message_at', 'resolved_at', 'closed_at', 'reopened_at', 'is_escalated', 'admin_locked', 'created_at']), 'uuid' => $t->getAttribute('public_uuid'), 'status' => $t->status->value, 'priority' => $t->priority->value, 'category' => $t->category->value, 'customer' => $this->name($t->customer->name), 'seller' => $t->getAttribute('seller_user_id') === null ? 'Templaara Support' : $t->seller->name, 'unread' => (int) ($t->getAttribute('unread_count') ?? 0)];
    }

    /**
     * @return array<string,mixed> */
    public function detail(SupportTicket $t, User $u, string $mode): array
    {
        $managed = $mode === 'admin' && $u->can('support.manage');
        $q = $t->messages()->with('attachments');
        if ($mode !== 'admin') {
            $q->where('is_internal', false);
        }$messages = $q->orderBy('id')->paginate(25, ['*'], 'messages_page')->withQueryString()->through(fn ($m) => $this->message($m, $t, $mode));
        $events = $t->events()->orderByDesc('sequence');
        if ($mode !== 'admin') {
            $events->whereIn('type', ['created', 'customer_replied', 'seller_replied', 'admin_replied', 'resolved', 'closed', 'reopened', 'purchase_refunded']);
        }
        $eligible = app(SupportEligibility::class)->eligible($t->customer, $t->item);
        $conversation = app(SupportEligibility::class)->eligible($t->customer, $t->item, false);

        return [...$this->row($t), 'messages' => $messages, 'events' => $events->paginate(25, ['type', 'previous_status', 'status', 'reason', 'occurred_at'], 'events_page')->withQueryString()->through(fn ($e) => [...$e->only(['type', 'previous_status', 'status', 'occurred_at']), 'reason' => $mode === 'admin' ? $e->getAttribute('reason') : ($e->getAttribute('type') === 'purchase_refunded' ? 'The purchased item was refunded.' : null)]), 'can_reply' => ($mode !== 'admin' || $managed) && $t->status->active() && $conversation, 'can_note' => $managed, 'can_resolve' => in_array($mode, ['author', 'admin']) && ($mode !== 'admin' || $managed) && $t->status->active(), 'can_close' => ($mode === 'customer' && $t->status->value === 'resolved') || ($managed && $t->status->value !== 'closed'), 'can_reopen' => in_array($mode, ['customer', 'admin']) && ($mode !== 'admin' || $managed) && $t->status->value === 'resolved' && $eligible, 'can_escalate' => ($mode === 'author' || $managed) && $t->status->value !== 'closed' && ! $t->getAttribute('is_escalated'), 'can_manage' => $managed];
    }

    /**
     * @return array<string,mixed> */
    private function message(SupportMessage $m, SupportTicket $t, string $mode): array
    {
        return [...$m->only(['public_uuid', 'body', 'is_internal', 'created_at']), 'sender' => $m->getAttribute('sender_type') === 'admin' ? 'Templaara Support' : ($m->getAttribute('sender_type') === 'seller' ? ($t->getAttribute('seller_user_id') === null ? 'Templaara Support' : $t->seller->name) : $this->name($t->customer->name)), 'sender_type' => $m->getAttribute('sender_type'), 'attachments' => $m->attachments->map(fn ($a) => [...$a->only(['public_uuid', 'original_name', 'mime_type', 'size_bytes']), 'url' => route($this->prefix($mode).'.attachments.download', $a)])->all()];
    }

    public function prefix(string $mode): string
    {
        return ($mode === 'customer' ? 'account' : $mode).'.support';
    }

    /**
     * @param Collection<int,OrderItem> $items
     * @return array<int,array<string,mixed>> */
    public function entries(?User $u, Collection $items): array
    {
        $eligible = $u ? app(SupportEligibility::class)->query($u)->whereIn('id', $items->pluck('id')->all())->pluck('id')->all() : [];
        $out = [];
        foreach ($items as $i) {
            $out[$i->id] = ['url' => in_array($i->id, $eligible, true) ? route('account.support.create', ['purchase' => $i->entitlement->getAttribute('public_id')]) : null];
        }

        return $out;
    }
}
