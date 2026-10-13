<?php

namespace App\Http\Controllers\Notifications;

use App\Enums\MarketplaceNotificationType as Type;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NotificationAction;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $v = $request->validate(['filter' => ['nullable', Rule::in(['all', 'unread'])]]);
        $page = $request->user()->notifications()->when(($v['filter'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))->reorder()->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $page->through(function ($n): array {
            $d = $n->data;
            $type = Type::tryFrom($d['type'] ?? '');

            return ['id' => $n->id, 'category' => $type?->group() ?? 'account', 'title' => (string) ($d['title'] ?? 'Account notification'), 'message' => (string) ($d['message'] ?? ''), 'action_label' => 'View details', 'action_path' => NotificationAction::safe($d['action_path'] ?? null) ? $d['action_path'] : null, 'occurred_at' => $d['occurred_at'] ?? $n->created_at->toIso8601String(), 'read_at' => $n->read_at?->toIso8601String()];
        });

        return Inertia::render('notifications/Index', ['notifications' => $page, 'filter' => $v['filter'] ?? 'all']);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        DB::transaction(function () use ($request, $notification): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $n = $request->user()->notifications()->whereKey($notification)->firstOrFail();
            if (! $n->read_at) {
                $n->markAsRead();
            }
        }, 3);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $request->user()->unreadNotifications()->update(['read_at' => now(), 'updated_at' => now()]);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notifications marked as read.']);

        return back();
    }

    public function preferences(Request $request, NotificationPreferences $preferences): Response
    {
        return Inertia::render('settings/Notifications', ['preferences' => $preferences->forUser($request->user())]);
    }

    public function updatePreferences(Request $request, NotificationPreferences $preferences): RedirectResponse
    {
        $groups = array_keys($preferences->groups());
        $v = $request->validate(['preferences' => ['required', 'array', 'size:8'], 'preferences.*' => ['required', 'array:group,in_app_enabled,email_enabled'], 'preferences.*.group' => ['required', 'distinct', Rule::in($groups)], 'preferences.*.in_app_enabled' => ['required', 'boolean'], 'preferences.*.email_enabled' => ['required', 'boolean'], 'user_id' => ['prohibited'], 'email' => ['prohibited'], 'recipient_user_id' => ['prohibited']]);
        DB::transaction(function () use ($request, $v): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            foreach ($v['preferences'] as $p) {
                DB::table('notification_preferences')->updateOrInsert(['user_id' => $request->user()->id, 'group' => $p['group']], ['in_app_enabled' => $p['in_app_enabled'], 'email_enabled' => $p['email_enabled'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification preferences saved.']);

        return back();
    }
}
