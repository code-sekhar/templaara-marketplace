<?php

namespace App\Services\Support;

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Events\Support\SupportMessageSent;
use App\Events\Support\SupportTicketClosed;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketEscalated;
use App\Events\Support\SupportTicketReopened;
use App\Events\Support\SupportTicketResolved;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class SupportWorkflow
{
    public function __construct(private SupportEligibility $eligibility, private SupportAttachments $files) {}

    /**
     * @param array<string,mixed> $input
     * @param array<mixed> $uploads */
    public function create(User $actor, OrderItem $purchase, array $input, array $uploads = []): SupportTicket
    {
        $v = $this->input($input, true);
        $files = $this->files->validate($uploads);
        $hash = $this->fingerprint([...$v, 'purchase' => $purchase->id], $files);
        $staged = $this->files->stage($files);
        try {
            return $this->atomic(function () use ($actor, $purchase, $v, $hash, $staged) {
                $this->lock($purchase);
                $i = OrderItem::with('order')->whereKey($purchase->id)->firstOrFail();
                abort_unless($i->order->user_id === $actor->id, 404);
                $old = SupportTicket::where('customer_user_id', $actor->id)->where('request_key', $v['request_key'])->first();
                if ($old) {
                    if ($old->getAttribute('request_hash') !== $hash) {
                        $this->error('request_key', 'This request key already identifies different support content.');
                    }

                    return $old;
                }
                if (! $this->eligibility->eligible($actor, $i)) {
                    $this->error('purchase', 'This purchase does not currently include eligible support.');
                }
                $t = (new SupportTicket)->forceFill(['public_uuid' => (string) Str::uuid(), 'ticket_number' => 'TMP-SUP-'.now()->year.'-'.Str::ulid(), 'request_key' => $v['request_key'], 'request_hash' => $hash, 'customer_user_id' => $actor->id, 'product_id' => $i->product_id, 'order_item_id' => $i->id, 'seller_user_id' => $i->getAttribute('author_user_id'), ...$i->only(['product_name', 'version', 'offer_name', 'license_type', 'support_period_days']), 'paid_at' => $i->order->getAttribute('paid_at'), 'support_ends_at' => $this->eligibility->ends($i), 'subject' => $v['subject'], 'category' => $v['category'], 'priority' => $v['priority'], 'status' => SupportStatus::AwaitingSeller, 'last_message_at' => now(), 'event_sequence' => 1]);
                $t->save();
                $this->message($t, $actor, 'customer', false, $v, $hash, $staged);
                $this->audit($t, $actor, 'created', null);
                SupportTicketCreated::dispatch($t->id);

                return $t;
            });
        } finally {
            $this->files->finish($staged);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<mixed> $uploads */
    public function reply(User $actor, SupportTicket $target, array $input, array $uploads = [], string $mode = 'customer', bool $internal = false): SupportTicket
    {
        $v = $this->input($input);
        $files = $this->files->validate($uploads);
        $hash = $this->fingerprint([...$v, 'internal' => $internal], $files);
        $staged = $this->files->stage($files);
        try {
            return $this->atomic(function () use ($actor, $target, $v, $staged, $hash, $mode, $internal) {
                $t = $this->locked($target);
                $this->authorize($actor, $t, $mode);
                abort_if($internal && $mode !== 'admin', 403);
                $old = $t->messages()->where('sender_user_id', $actor->id)->where('request_key', $v['request_key'])->first();
                if ($old) {
                    if ($old->getAttribute('request_hash') !== $hash) {
                        $this->error('request_key', 'This message key already identifies different content.');
                    }

                    return $t;
                }
                if (! $internal) {
                    if (! $t->status->active()) {
                        $this->error('ticket', 'This ticket is resolved or closed. Reopen a resolved ticket before replying.');
                    }if (! $this->eligibility->eligible($t->customer, $t->item, false)) {
                        $this->error('ticket', 'This purchase no longer qualifies for an active support conversation.');
                    }
                }
                $from = $t->status->value;
                $fields = $internal ? [] : ['status' => $mode === 'customer' ? SupportStatus::AwaitingSeller : SupportStatus::AwaitingCustomer, 'last_message_at' => now()];
                $t->forceFill([...$fields, 'event_sequence' => $t->getAttribute('event_sequence') + 1])->save();
                $type = $mode === 'author' ? 'seller' : $mode;
                $this->message($t, $actor, $type, $internal, $v, $hash, $staged);
                $this->audit($t, $actor, $internal ? 'internal_note_added' : $type.'_replied', $from);
                SupportMessageSent::dispatch($t->id);

                return $t;
            });
        } finally {
            $this->files->finish($staged);
        }
    }

    /**
     * @param array<string,mixed> $input */
    public function transition(User $actor, SupportTicket $target, string $action, array $input = [], string $mode = 'customer'): SupportTicket
    {
        return $this->atomic(function () use ($actor, $target, $action, $input, $mode) {
            $t = $this->locked($target);
            $this->authorize($actor, $t, $mode);
            $from = $t->status->value;
            $fields = [];
            $reason = null;
            $type = $action;
            if ($action === 'resolve') {
                abort_unless(in_array($mode, ['author', 'admin']), 403);
                if (! $t->status->active()) {
                    $this->error('ticket', 'Only an active ticket can be resolved.');
                }$fields = ['status' => SupportStatus::Resolved, 'resolved_at' => now()];
                $type = 'resolved';
            } elseif ($action === 'close') {
                if ($t->status === SupportStatus::Closed) {
                    $this->error('ticket', 'This ticket is already closed.');
                }if ($mode !== 'admin') {
                    abort_unless($mode === 'customer', 403);
                    if ($t->status !== SupportStatus::Resolved) {
                        $this->error('ticket', 'Confirm resolution before closing this ticket.');
                    }
                } else {
                    $reason = $this->reason($input);
                }$fields = ['status' => SupportStatus::Closed, 'closed_at' => now(), 'admin_locked' => $mode === 'admin'];
                $type = 'closed';
            } elseif ($action === 'reopen') {
                abort_unless(in_array($mode, ['customer', 'admin']), 403);
                if ($t->status !== SupportStatus::Resolved || $t->getAttribute('admin_locked') || ! $this->eligibility->eligible($t->customer, $t->item)) {
                    $this->error('ticket', 'Only a resolved ticket with eligible purchased support can be reopened. Closed or refunded tickets remain terminal.');
                }$fields = ['status' => SupportStatus::AwaitingSeller, 'reopened_at' => now()];
                $type = 'reopened';
            } elseif ($action === 'priority') {
                abort_unless($mode === 'admin', 403);
                $v = Validator::make($input, ['priority' => ['required', new Enum(SupportPriority::class)]])->validate();
                if ($t->priority->value === $v['priority']) {
                    $this->error('priority', 'The priority is unchanged.');
                }$fields = ['priority' => $v['priority']];
                $type = 'priority_changed';
            } elseif (in_array($action, ['escalate', 'deescalate'])) {
                abort_unless($mode === 'admin' || ($mode === 'author' && $action === 'escalate'), 403);
                $reason = $this->reason($input);
                $escalate = $action === 'escalate';
                if ($t->status === SupportStatus::Closed || $t->getAttribute('is_escalated') === $escalate) {
                    $this->error('ticket', 'This escalation action is unavailable.');
                }$fields = ['is_escalated' => $escalate];
                $type = $escalate ? 'escalated' : 'deescalated';
            } else {
                $this->error('ticket', 'Unknown support action.');
            }
            $t->forceFill([...$fields, 'event_sequence' => $t->getAttribute('event_sequence') + 1])->save();
            $this->audit($t, $actor, $type, $from, $reason);
            $event = match ($type) {
                'resolved' => SupportTicketResolved::class,'closed' => SupportTicketClosed::class,'reopened' => SupportTicketReopened::class,'escalated' => SupportTicketEscalated::class,default => null
            };
            if ($event) {
                $event::dispatch($t->id);
            }

            return $t;
        });
    }

    /** Called inside the already buyer/seller-locked refund completion transaction. */
    public function refunded(int $item): void
    {
        foreach (SupportTicket::where('order_item_id', $item)->where('status', '!=', 'closed')->orderBy('id')->lockForUpdate()->get() as $t) {
            $from = $t->status->value;
            $t->forceFill(['status' => SupportStatus::Closed, 'admin_locked' => true, 'closed_at' => now(), 'event_sequence' => $t->getAttribute('event_sequence') + 1])->save();
            $this->audit($t, null, 'purchase_refunded', $from, 'The purchased item was refunded.');
            SupportTicketClosed::dispatch($t->id);
        }
    }

    public function read(User $actor, SupportTicket $target, string $mode): SupportTicket
    {
        return $this->atomic(function () use ($actor, $target, $mode) {
            $t = $this->locked($target);
            $this->authorize($actor, $t, $mode);
            if ($mode !== 'admin') {
                $t->forceFill([($mode === 'customer' ? 'customer_last_read_at' : 'seller_last_read_at') => now(), ($mode === 'customer' ? 'customer_last_read_sequence' : 'seller_last_read_sequence') => $t->getAttribute('event_sequence')])->save();
            }

            return $t;
        });
    }

    private function locked(SupportTicket $t): SupportTicket
    {
        $this->lock($t->item);

        return SupportTicket::whereKey($t->id)->lockForUpdate()->firstOrFail();
    }

    private function lock(OrderItem $i): void
    {
        $i->loadMissing('order');
        $ids = collect([$i->order->user_id, $i->getAttribute('author_user_id')])->filter()->unique()->sort()->values();
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        Order::whereKey($i->order->id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(User $u, SupportTicket $t, string $mode): void
    {
        abort_unless(in_array($mode, ['customer', 'author', 'admin']), 403);
        if ($mode === 'admin') {
            Gate::forUser($u)->authorize('manage', $t);
        } else {
            abort_unless(Gate::forUser($u)->allows($mode === 'author' ? 'seller' : 'customer', $t), 404);
        }
    }

    /**
     * @param array<string,mixed> $v
     * @param array<int,array<string,mixed>> $files */
    private function message(SupportTicket $t, User $u, string $type, bool $internal, array $v, string $hash, array $files): void
    {
        $m = (new SupportMessage)->forceFill(['public_uuid' => (string) Str::uuid(), 'ticket_id' => $t->id, 'sender_user_id' => $u->id, 'sender_type' => $type, 'is_internal' => $internal, 'body' => $v['body'], 'request_key' => $v['request_key'], 'request_hash' => $hash, 'sequence' => $t->getAttribute('event_sequence'), 'created_at' => $internal ? now() : $t->getAttribute('last_message_at')]);
        $m->save();
        foreach ($files as $f) {
            (new SupportAttachment)->forceFill(['message_id' => $m->id, 'uploader_user_id' => $u->id, ...array_intersect_key($f, array_flip(['public_uuid', 'original_name', 'mime_type', 'size_bytes', 'checksum', 'disk', 'path'])), 'created_at' => now()])->save();
        }
    }

    private function audit(SupportTicket $t, ?User $u, string $type, ?string $from, ?string $reason = null): void
    {
        (new SupportTicketEvent)->forceFill(['ticket_id' => $t->id, 'actor_id' => $u?->id, 'sequence' => $t->getAttribute('event_sequence'), 'type' => $type, 'previous_status' => $from, 'status' => $t->status->value, 'reason' => $reason, 'occurred_at' => now()])->save();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function input(array $input, bool $create = false): array
    {
        $rules = ['request_key' => ['required', 'uuid'], 'body' => ['bail', 'required', 'string', 'min:1', 'max:10000', $this->plain()]];
        if ($create) {
            $rules += ['subject' => ['bail', 'required', 'string', 'min:3', 'max:200', $this->plain()], 'category' => ['required', new Enum(SupportCategory::class)], 'priority' => ['required', new Enum(SupportPriority::class)]];
        }foreach (['customer_user_id', 'product_id', 'seller_user_id', 'status', 'sender_user_id', 'sender_type', 'is_internal', 'support_period_days', 'version', 'paid_at', 'last_message_at', 'storage_path', 'admin_locked'] as $k) {
            $rules[$k] = ['prohibited'];
        }foreach (['body', 'subject'] as $k) {
            if (is_string($input[$k] ?? null)) {
                $input[$k] = trim($input[$k]);
            }
        }

        return Validator::make($input, $rules)->validate();
    }

    private function plain(): \Closure
    {
        return function ($a, $v, $fail) {
            if (strip_tags($v) !== $v) {
                $fail('Use plain text without HTML.');
            }
        };
    }

    /**
     * @param array<string,mixed> $input */
    private function reason(array $input): string
    {
        return Validator::make($input, ['reason' => ['bail', 'required', 'string', 'min:5', 'max:2000', $this->plain()]])->validate()['reason'];
    }

    /**
     * @param array<string,mixed> $v
     * @param array<int,array<string,mixed>> $files */
    private function fingerprint(array $v, array $files): string
    {
        return hash('sha256', json_encode([$v, array_map(fn ($f) => array_intersect_key($f, array_flip(['original_name', 'checksum', 'mime_type', 'size_bytes'])), $files)], JSON_THROW_ON_ERROR));
    }

    private function error(string $k, string $m): never
    {
        throw ValidationException::withMessages([$k => $m]);
    }

    /**
     * @param callable():SupportTicket $fn */
    private function atomic(callable $fn): SupportTicket
    {
        try {
            return DB::transaction(fn () => $fn(), 3);
        } catch (QueryException $e) {
            $this->error('ticket', 'This support request changed or could not be recorded safely. Refresh and retry.');
        }
    }
}
