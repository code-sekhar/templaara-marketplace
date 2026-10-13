<?php

use App\Enums\AuthorApplicationAction as AA;
use App\Enums\MarketplaceNotificationType as NT;
use App\Enums\ProductWorkflowAction as PA;
use App\Events\Commerce\OrderPaid;
use App\Events\Reviews\ProductReviewCreated;
use App\Jobs\DeliverNotificationEmail;
use App\Models\AuthorEarning;
use App\Models\CommissionPolicy;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\Technology;
use App\Models\User;
use App\Notifications\MarketplaceNotification;
use App\Notifications\NotificationAction;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\NotificationRecovery;
use App\Services\Payouts\PayoutWorkflow;
use App\Services\Products\ProductUpdates;
use App\Services\Products\Workflow\ProductWorkflow;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Reviews\ReviewWorkflow;
use App\Services\Support\SupportWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthorFixtures as AF;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures as EF;
use Tests\Support\EntitlementFixtures as EN;
use Tests\Support\PayoutFixtures as PF;
use Tests\Support\ProductFileFixtures as FF;
use Tests\Support\RefundFixtures as RF;
use Tests\Support\ReviewFixtures as VF;
use Tests\Support\SupportFixtures as SF;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    FF::fakeStorage();
    config()->set('support.attachment_disk', config('product_files.artifact_disk'));
    config()->set('notifications.started_at', '2000-01-01 00:00:00+00');
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('mail.default', 'array');
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
});
function recover16(): array
{
    return app(NotificationRecovery::class)->run();
}
function notices16(User $u, string $type): Collection
{
    return $u->notifications()->where('data->type', $type)->get();
}
function staff16(): User
{
    return User::factory()->create()->assignRole('admin');
}
function preference16(User $u, string $group, bool $in = true, bool $email = true): void
{
    DB::table('notification_preferences')->updateOrInsert(['user_id' => $u->id, 'group' => $group], ['in_app_enabled' => $in, 'email_enabled' => $email, 'created_at' => now(), 'updated_at' => now()]);
}
function draft16(): Product
{
    $a = User::factory()->create()->assignRole('author');
    $p = Product::factory()->create(['owner_id' => $a->id, 'origin' => 'author', 'short_description' => 'Business inventory and billing for retail operations.', 'description' => 'Full inventory and business billing software for retail operations.', 'business_problem' => 'Replace disconnected manual inventory and billing.', 'demo_url' => 'https://demo.example.com']);
    $p->industries()->attach(Industry::factory()->create());
    $p->technologies()->attach(Technology::factory()->create());
    ProductFeature::factory()->for($p)->create();
    ProductOffer::factory()->for($p)->create();
    FF::prepare($p, $a);

    return $p;
}

test('notification guest cannot read center or preferences', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/notifications', '/settings/notifications']);
test('notification empty center has clean owned state', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/notifications')->assertInertia(fn (Assert $p) => $p->component('notifications/Index')->has('notifications.data', 0)->where('notificationUnreadCount', 0));
});
test('purchase ready is emitted once after provisioned verified payment', function () {
    $o = SF::order();
    $u = $o->user;
    recover16();
    recover16();
    $n = notices16($u, 'purchase_ready');
    expect($n)->toHaveCount(1)->and($n->first()->data['action_path'])->toBe(route('commerce.orders.show', $o, false));
    expect(DB::table('notification_deliveries')->where('user_id', $u->id)->where('notification_type', 'purchase_ready')->count())->toBe(1);
});
test('unpaid or failed purchase is never described as ready', function (string $state) {
    $o = VF::purchase(paid: false);
    if ($state === 'failed') {
        EN::pay($o, 'failed');
    } recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(0);
})->with(['unpaid', 'failed']);
test('notification access provisioning recovery does not replay payment', function () {
    $o = VF::purchase(paid: false);
    Event::fake([OrderPaid::class]);
    $o = EN::pay($o);
    recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(0);
    app(EntitlementProvisioner::class)->provision($o);
    recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(1)->and($o->payments()->where('status', 'paid')->count())->toBe(1);
});
test('notification rollback retains no delivery work', function () {
    $before = DB::table('notifications')->count();
    $outbox = DB::table('notification_deliveries')->count();
    DB::beginTransaction();
    $o = SF::order();
    DB::rollBack();
    recover16();
    expect(DB::table('notifications')->count())->toBe($before)->and(DB::table('notification_deliveries')->count())->toBe($outbox);
});
test('notification process crash before listener is recoverable', function () {
    Event::fake([OrderPaid::class]);
    $o = SF::order();
    app(EntitlementProvisioner::class)->provision($o);
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(0);
    recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(1);
});
test('notification multi role recipient is not duplicated', function () {
    $o = SF::order();
    $o->user->assignRole('author', 'admin', 'reviewer');
    recover16();
    event(new OrderPaid($o));
    recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(1);
});
test('notification ownership listing and single read reject IDOR even admin', function () {
    $o = SF::order();
    recover16();
    $n = notices16($o->user, 'purchase_ready')->first();
    $other = staff16();
    $this->actingAs($other)->get('/notifications')->assertInertia(fn (Assert $p) => $p->has('notifications.data', 0));
    $this->post(route('notifications.read', $n->id))->assertNotFound();
    expect($n->fresh()->read_at)->toBeNull();
});
test('notification mark read is idempotent and unread count authoritative', function () {
    $o = SF::order();
    recover16();
    $n = notices16($o->user, 'purchase_ready')->first();
    $this->actingAs($o->user)->get('/notifications')->assertInertia(fn (Assert $p) => $p->where('notificationUnreadCount', 1));
    $this->post(route('notifications.read', $n->id))->assertRedirect();
    $at = $n->fresh()->read_at->toISOString();
    $this->travel(1)->minutes();
    $this->post(route('notifications.read', $n->id))->assertRedirect();
    expect($n->fresh()->read_at->toISOString())->toBe($at);
    $this->get('/notifications?filter=unread')->assertInertia(fn (Assert $p) => $p->has('notifications.data', 0)->where('notificationUnreadCount', 0));
    $this->travelBack();
});
test('notification mark all leaves other users untouched', function () {
    $o = SF::order();
    $t = SF::ticket($o);
    recover16();
    $seller = $t->seller;
    $this->actingAs($o->user)->post('/notifications/read-all')->assertRedirect();
    expect($o->user->unreadNotifications()->count())->toBe(0)->and($seller->unreadNotifications()->count())->toBeGreaterThan(0);
});
test('notification pagination deterministic preserves unread filter', function () {
    $u = User::factory()->create();
    for ($i = 0; $i < 25; $i++) {
        $u->notifyNow(new MarketplaceNotification((string) Str::uuid(), ['type' => 'purchase_ready', 'title' => 'Purchase ready', 'message' => 'Ready', 'action_path' => '/account/orders/example', 'occurred_at' => now()->toISOString()]), ['database']);
    }$this->actingAs($u)->get('/notifications?filter=unread')->assertInertia(fn (Assert $p) => $p->has('notifications.data', 20)->where('notifications.total', 25)->where('filter', 'unread'));
    $first = $u->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id')->first()->id;
    $this->get('/notifications?filter=unread')->assertInertia(fn (Assert $p) => $p->where('notifications.data.0.id', $first));
});
test('notification filter input controlled', function () {
    $this->actingAs(User::factory()->create())->get('/notifications?filter=admin')->assertSessionHasErrors('filter');
});
test('notification opening destination does not implicitly mark read', function () {
    $o = SF::order();
    recover16();
    $n = notices16($o->user, 'purchase_ready')->first();
    $this->actingAs($o->user)->get($n->data['action_path'])->assertOk();
    expect($n->fresh()->read_at)->toBeNull();
});
test('notification payload grants no destination authorization', function () {
    $o = SF::order();
    recover16();
    $n = notices16($o->user, 'purchase_ready')->first();
    $this->actingAs(User::factory()->create())->get($n->data['action_path'])->assertForbidden();
});
test('notification presentation hides raw delivery identity and user email', function () {
    $o = SF::order();
    recover16();
    $this->actingAs($o->user)->get('/notifications')->assertInertia(fn (Assert $p) => $p->missing('notifications.data.0.notifiable_id')->missing('notifications.data.0.data')->missing('notifications.data.0.user_id')->missing('notifications.data.0.email')->missing('notifications.data.0.source'));
});

test('refund notifications use immutable lifecycle truth', function (string $state) {
    $r = RF::request();
    $s = app(RefundWorkflow::class);
    $a = staff16();
    if (in_array($state, ['approved', 'processing', 'completed', 'failed'])) {
        $s->transition($a, $r, 'approve', admin: true);
    }if (in_array($state, ['processing', 'completed', 'failed'])) {
        $s->transition($a, $r, 'process', admin: true);
    }if ($state === 'completed') {
        $s->transition($a, $r, 'complete', ['external_reference' => 'REF-VERIFIED'], true);
    }if ($state === 'failed') {
        $s->transition($a, $r, 'fail', ['reason' => 'External settlement failed'], true);
    }if ($state === 'cancelled') {
        $s->transition($a, $r, 'cancel', ['reason' => 'Cancelled before processing'], true);
    }recover16();
    $n = notices16(User::findOrFail($r->customer_user_id), 'refund_'.$state)->first();
    expect($n)->not->toBeNull()->and($n->data['action_path'])->toBe(route('account.refunds.show', $r, false));
    if (in_array($state, ['requested', 'approved', 'processing'])) {
        expect($n->data['title'])->not->toContain('completed');
    }recover16();
    expect(notices16(User::findOrFail($r->customer_user_id), 'refund_'.$state))->toHaveCount(1);
})->with(['requested', 'approved', 'processing', 'completed', 'failed', 'cancelled']);
test('payout notifications never call processing paid', function (string $state) {
    $e = PF::available();
    $u = $e->author;
    $p = PF::request($u);
    $s = app(PayoutWorkflow::class);
    $a = staff16();
    if (in_array($state, ['approved', 'processing', 'paid', 'failed'])) {
        $s->transition($a, $p, 'approve', asAdmin: true);
    }if (in_array($state, ['processing', 'paid', 'failed'])) {
        $s->transition($a, $p, 'process', asAdmin: true);
    }if ($state === 'paid') {
        $s->transition($a, $p, 'pay', ['external_reference' => 'TRANSFER-CONFIRMED'], true);
    }if ($state === 'failed') {
        $s->transition($a, $p, 'fail', ['reason' => 'Transfer rejected by destination'], true);
    }if ($state === 'cancelled') {
        $s->transition($a, $p, 'cancel', ['reason' => 'Cancelled before transfer'], true);
    }recover16();
    $n = notices16($u, 'payout_'.$state)->first();
    expect($n)->not->toBeNull()->and($n->data['action_path'])->toBe(route('author.payouts.show', $p, false));
    if ($state === 'processing') {
        expect($n->data['title'])->not->toContain('completed');
    }expect(json_encode($n->data))->not->toContain('123456789012', 'ABCD0123456', 'account_holder');
    recover16();
    expect(notices16($u, 'payout_'.$state))->toHaveCount(1);
})->with(['requested', 'approved', 'processing', 'paid', 'failed', 'cancelled']);
test('author application decision notification uses applicant only', function (string $state) {
    $a = AF::submitted();
    $staff = staff16();
    $s = app(AuthorWorkflow::class);
    if ($state !== 'submitted') {
        $s->transition($staff, $a->profile, AA::Start, $a->id);
        $action = match ($state) {
            'changes_requested' => AA::Changes,'approved' => AA::Approve,'rejected' => AA::Reject
        };
        $input = $state === 'changes_requested' ? ['feedback' => [['section' => 'general', 'message' => 'Please clarify your business experience.']]] : ($state === 'rejected' ? ['summary' => 'The application does not meet requirements.'] : []);
        $s->transition($staff, $a->profile, $action, $a->id, $input);
    }recover16();
    expect(notices16($a->user, 'application_'.$state))->toHaveCount(1)->and($staff->notifications()->count())->toBe(0);
})->with(['submitted', 'changes_requested', 'approved', 'rejected']);
test('product lifecycle notifications are owner scoped and precise', function (string $state) {
    $p = draft16();
    $w = app(ProductWorkflow::class);
    $a = staff16();
    $r = $w->transition($p->owner, $p, PA::Submit);
    if ($state !== 'submitted') {
        $w->transition($a, $p, PA::StartReview, $r->id);
        $action = match ($state) {
            'changes_requested' => PA::RequestChanges,'rejected' => PA::Reject,default => PA::Approve
        };
        $input = $state === 'changes_requested' ? ['feedback' => [['section' => 'general', 'message' => 'Please explain installation requirements.']]] : ($state === 'rejected' ? ['summary' => 'This product does not meet requirements.'] : []);
        $w->transition($a, $p, $action, $r->id, $input);
        if ($state === 'published') {
            $w->transition($a, $p, PA::Publish);
        }
    }recover16();
    expect(notices16($p->owner, 'product_'.$state))->toHaveCount(1)->and($a->notifications()->count())->toBe(0);
})->with(['submitted', 'changes_requested', 'approved', 'rejected', 'published']);
test('official product moderation creates no fake owner notification', function () {
    $p = CommerceFixtures::product();
    recover16();
    expect(DB::table('notification_deliveries')->where('source', 'product_lifecycle_events')->count())->toBe(0);
    expect($p->owner_id)->toBeNull();
});
test('update lifecycle notification distinct from initial product', function (string $state) {
    $p = EF::product();
    $u = $p->owner;
    $s = app(ProductUpdates::class);
    $r = $s->create($u, $p, '1.1.0');
    $s->release($u, $r, ['version' => '1.1.0', 'changelog' => 'Improved inventory reporting.']);
    $s->upload($u, $r, 'source_package', FF::zip());
    $a = staff16();
    $round = $s->transition($u, $r, PA::Submit);
    if ($state !== 'submitted') {
        $s->transition($a, $r, PA::StartReview, [], $round->id);
        $action = match ($state) {
            'changes_requested' => PA::RequestChanges,'rejected' => PA::Reject,default => PA::Approve
        };
        $input = $state === 'changes_requested' ? ['feedback' => [['section' => 'general', 'message' => 'Explain what this update changes.']]] : ($state === 'rejected' ? ['summary' => 'This release does not meet requirements.'] : []);
        $s->transition($a, $r, $action, $input, $round->id);
        if ($state === 'published') {
            $s->transition($a, $r, PA::Publish);
        }
    }recover16();
    expect(notices16($u, 'update_'.$state))->toHaveCount(1)->and(notices16($u, 'product_published'))->toHaveCount(1);
    expect(notices16($u, 'update_'.$state)->first()->data['action_path'])->toBe(route('products.updates.show', [$p, $r], false));
})->with(['submitted', 'changes_requested', 'approved', 'rejected', 'published']);
test('earning notifications contain no buyer or payment identity', function (string $type) {
    $o = SF::order();
    $e = AuthorEarning::where('order_id', $o->id)->firstOrFail();
    if ($type === 'earning_available') {
        app(ReleaseEarnings::class)->release($e);
    }recover16();
    $n = notices16($e->author, $type)->first();
    expect($n)->not->toBeNull()->and(json_encode($n->data))->not->toContain($o->user->email, $o->order_number, 'payment', 'billing');
    expect(DB::table('notification_deliveries')->where('id', $n->id)->value('email_status'))->toBe('skipped');
})->with(['earning_recorded', 'earning_available']);
test('public verified review notifies seller once', function () {
    $r = VF::review();
    recover16();
    recover16();
    expect(notices16($r->product->owner, 'review_received'))->toHaveCount(1);
});
test('hidden unqualified review is not announced by recovery', function (string $action) {
    Event::fake([ProductReviewCreated::class]);
    $r = VF::review();
    $s = app(ReviewWorkflow::class);
    if ($action === 'hidden') {
        $s->action(staff16(), $r, 'hide', ['reason' => 'Moderation hides this customer review.']);
    } else {
        $s->action($r->customer, $r, 'withdraw');
    }recover16();
    expect(notices16($r->product->owner, 'review_received'))->toHaveCount(0);
})->with(['hidden', 'withdrawn']);
test('seller response notifies customer without copying response', function () {
    $r = VF::review();
    app(ReviewWorkflow::class)->action($r->product->owner, $r, 'respond', ['body' => 'PRIVATE RESPONSE BODY']);
    recover16();
    $n = notices16($r->customer, 'review_response')->first();
    expect($n)->not->toBeNull()->and(json_encode($n->data))->not->toContain('PRIVATE RESPONSE BODY');
});

test('new support ticket notifies historical seller', function () {
    $t = SF::ticket();
    recover16();
    expect(notices16($t->seller, 'support_created'))->toHaveCount(1)->and(notices16($t->customer, 'support_created'))->toHaveCount(0);
});
test('support reply delivers only to opposite participant without body', function (string $mode) {
    $t = SF::ticket();
    $actor = $mode === 'author' ? $t->seller : $t->customer;
    $recipient = $mode === 'author' ? $t->customer : $t->seller;
    app(SupportWorkflow::class)->reply($actor, $t, SF::reply('PRIVATE TECHNICAL BODY'), [], $mode);
    recover16();
    recover16();
    $type = $mode === 'author' ? 'support_seller_reply' : 'support_customer_reply';
    $n = notices16($recipient, $type)->first();
    expect($n)->not->toBeNull()->and(json_encode($n->data))->not->toContain('PRIVATE TECHNICAL BODY');
    expect(notices16($recipient, $type))->toHaveCount(1);
})->with(['author', 'customer']);
test('internal support notes produce no participant delivery', function () {
    $t = SF::ticket();
    recover16();
    $before = DB::table('notification_deliveries')->count();
    app(SupportWorkflow::class)->reply(staff16(), $t, SF::reply('PRIVATE STAFF NOTE'), [], 'admin', true);
    recover16();
    expect(DB::table('notification_deliveries')->count())->toBe($before);
});
test('support resolution notifies customer', function () {
    $t = SF::ticket();
    app(SupportWorkflow::class)->transition($t->seller, $t, 'resolve', [], 'author');
    recover16();
    expect(notices16($t->customer, 'support_resolved'))->toHaveCount(1);
});
test('staff support closure excludes private reason', function () {
    $t = SF::ticket();
    app(SupportWorkflow::class)->transition(staff16(), $t, 'close', ['reason' => 'PRIVATE ADMIN REASON'], 'admin');
    recover16();
    $n = notices16($t->customer, 'support_closed')->first();
    expect($n)->not->toBeNull()->and(json_encode($n->data))->not->toContain('PRIVATE ADMIN REASON');
});
test('refunded support closure remains generic and completion unique', function () {
    $t = SF::ticket();
    $r = RF::request($t->item->order);
    RF::complete($r);
    recover16();
    recover16();
    expect(notices16($t->customer, 'support_closed'))->toHaveCount(1)->and(notices16($t->customer, 'refund_completed'))->toHaveCount(1);
    $n = notices16($t->customer, 'support_closed')->first();
    expect(json_encode($n->data))->not->toContain($r->getAttribute('customer_reason'), 'refund_id', 'compensation');
});
test('official support replies use customer recipient without fake seller', function () {
    $t = SF::ticket(SF::order(official: true));
    app(SupportWorkflow::class)->reply(staff16(), $t, SF::reply(), [], 'admin');
    recover16();
    expect(notices16($t->customer, 'support_seller_reply'))->toHaveCount(1)->and(DB::table('notification_deliveries')->where('notification_type', 'support_created')->count())->toBe(0);
});
test('staff deescalation notifies historical seller', function () {
    $t = SF::ticket();
    $a = staff16();
    $s = app(SupportWorkflow::class);
    $s->transition($a, $t, 'escalate', ['reason' => 'Operational help required.'], 'admin');
    $s->transition($a, $t, 'deescalate', ['reason' => 'Operational issue resolved.'], 'admin');
    recover16();
    expect(notices16($t->seller, 'support_escalated'))->toHaveCount(1)->and(notices16($t->seller, 'support_deescalated'))->toHaveCount(1);
});

test('preferences default groups conservative and safe', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/settings/notifications')->assertInertia(fn (Assert $p) => $p->component('settings/Notifications')->has('preferences', 8)->missing('preferences.0.user_id'));
    expect(app(NotificationPreferences::class)->channels($u, NT::EarningRecorded))->toBe(['in_app' => true, 'email' => false]);
});
test('preference mutation owner scoped', function () {
    $u = User::factory()->create();
    $other = User::factory()->create();
    $rows = app(NotificationPreferences::class)->forUser($u);
    $rows = array_map(fn ($r) => array_intersect_key($r, array_flip(['group', 'in_app_enabled', 'email_enabled'])), $rows);
    $rows[0]['email_enabled'] = false;
    $this->actingAs($u)->put('/settings/notifications', ['preferences' => $rows])->assertRedirect();
    expect(DB::table('notification_preferences')->where('user_id', $u->id)->count())->toBe(8)->and(DB::table('notification_preferences')->where('user_id', $other->id)->count())->toBe(0);
});
test('preference malformed or forged fields rejected', function (string $field, mixed $value) {
    $u = User::factory()->create();
    $rows = array_map(fn ($r) => array_intersect_key($r, array_flip(['group', 'in_app_enabled', 'email_enabled'])), app(NotificationPreferences::class)->forUser($u));
    $input = ['preferences' => $rows];
    if (str_starts_with($field, 'preferences.')) {
        $input['preferences'][0][substr($field, 12)] = $value;
    } else {
        $input[$field] = $value;
    }$this->actingAs($u)->put('/settings/notifications', $input)->assertSessionHasErrors(str_starts_with($field, 'preferences.') ? str_replace('preferences.', 'preferences.0.', $field) : $field);
    expect(DB::table('notification_preferences')->count())->toBe(0);
})->with([['preferences.group', 'marketing'], ['preferences.in_app_enabled', 'yes'], ['preferences.email_enabled', []], ['user_id', 999], ['recipient_user_id', 999], ['email', 'another@example.com']]);
test('preference duplicate group fails', function () {
    $u = User::factory()->create();
    $rows = array_map(fn ($r) => array_intersect_key($r, array_flip(['group', 'in_app_enabled', 'email_enabled'])), app(NotificationPreferences::class)->forUser($u));
    $rows[1]['group'] = $rows[0]['group'];
    $this->actingAs($u)->put('/settings/notifications', ['preferences' => $rows])->assertSessionHasErrors('preferences.0.group');
});
test('mandatory in app truth cannot be disabled', function (string $type) {
    $u = User::factory()->create();
    $t = NT::from($type);
    preference16($u, $t->group(), false, false);
    expect(app(NotificationPreferences::class)->channels($u, $t))->toBe(['in_app' => true, 'email' => false]);
})->with(['purchase_ready', 'refund_completed', 'payout_paid']);
test('group preference affects only configurable future delivery', function (string $group) {
    $u = User::factory()->create();
    preference16($u, $group, false, false);
    $types = array_filter(NT::cases(), fn ($t) => $t->group() === $group && ! $t->mandatory());
    foreach ($types as $t) {
        expect(app(NotificationPreferences::class)->channels($u, $t))->toBe(['in_app' => false, 'email' => false]);
    }
})->with(['refunds', 'author_workflow', 'product_updates', 'earnings', 'payouts', 'reviews', 'support']);
test('support email opt out suppresses queued record channel', function () {
    $t = SF::ticket();
    preference16($t->customer, 'support', true, false);
    app(SupportWorkflow::class)->reply($t->seller, $t, SF::reply(), [], 'author');
    recover16();
    $n = notices16($t->customer, 'support_seller_reply')->first();
    expect($n)->not->toBeNull()->and(DB::table('notification_deliveries')->where('id', $n->id)->value('email_status'))->toBe('skipped');
});
test('preference snapshot is immutable after discovery before queue', function () {
    $t = SF::ticket();
    app(SupportWorkflow::class)->reply($t->seller, $t, SF::reply(), [], 'author');
    recover16();
    $n = notices16($t->customer, 'support_seller_reply')->first();
    preference16($t->customer, 'support', false, false);
    recover16();
    expect(DB::table('notification_deliveries')->where('id', $n->id)->value('email_enabled'))->toBeTruthy()->and(notices16($t->customer, 'support_seller_reply'))->toHaveCount(1);
});
test('notification queue replay retains one delivery and no inline mail', function () {
    Queue::fake();
    Mail::fake();
    $o = SF::order();
    recover16();
    $r = app(NotificationRecovery::class);
    $r->dispatchPending();
    $r->dispatchPending();
    Queue::assertPushed(DeliverNotificationEmail::class, fn ($job) => $job->deliveryId === notices16($o->user, 'purchase_ready')->first()->id);
    Mail::assertNothingSent();
    expect(DB::table('notification_deliveries')->where('user_id', $o->user_id)->where('notification_type', 'purchase_ready')->count())->toBe(1);
});
test('notification dispatch rejects synchronous or non durable connection', function (string $driver) {
    $o = SF::order();
    recover16();
    config()->set('notifications.connection', 'unsafe');
    config()->set('queue.connections.unsafe.driver', $driver);
    expect(fn () => app(NotificationRecovery::class)->dispatchPending())->toThrow(LogicException::class);
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(1);
})->with(['sync', 'deferred', 'background', 'null']);
test('email delivery refuses transactions', function () {
    $o = SF::order();
    recover16();
    $n = notices16($o->user, 'purchase_ready')->first();
    Mail::fake();
    expect(fn () => (new DeliverNotificationEmail($n->id))->handle())->toThrow(LogicException::class);
    Mail::assertNothingSent();
    expect($o->fresh()->getRawOriginal('payment_status'))->toBe('paid');
});
test('notification email retry budget and backoff bounded', function () {
    $j = new DeliverNotificationEmail((string) Str::uuid());
    expect($j->tries)->toBe(5)->and($j->timeout)->toBe(45)->and($j->backoff)->toBe([60, 300, 900, 1800]);
});
test('lost email worker acknowledgement becomes uncertain not resent', function () {
    Queue::fake();
    $o = SF::order();
    recover16();
    $id = notices16($o->user, 'purchase_ready')->first()->id;
    DB::table('notification_deliveries')->where('id', $id)->update(['email_status' => 'sending', 'attempts' => 1, 'claimed_at' => now()->subMinutes(6), 'claim_token' => (string) Str::uuid()]);
    app(NotificationRecovery::class)->dispatchPending();
    expect(DB::table('notification_deliveries')->where('id', $id)->value('email_status'))->toBe('uncertain');
    Queue::assertNotPushed(DeliverNotificationEmail::class, fn ($job) => $job->deliveryId === $id);
});
test('email links use configured origin and safe internal path', function () {
    config()->set('app.url', 'https://marketplace.example');
    $n = new MarketplaceNotification((string) Str::uuid(), ['title' => 'New support reply', 'message' => 'Open your ticket securely.', 'action_path' => '/account/support/'.Str::uuid()]);
    $mail = $n->toMail(User::factory()->create());
    expect($mail->actionUrl)->toStartWith('https://marketplace.example/account/support/')->and($mail->subject)->toBe('New support reply');
});
test('unsafe action route is rejected', function (string $path) {
    expect(NotificationAction::safe($path))->toBeFalse();
})->with(['https://evil.example', '//evil.example', '/admin/orders/1', '/account/support/../admin', '/account/support/%2e%2e/admin', "/account/support/a\r\nLocation:evil", '/account/support/\\evil', '/account/support/https://evil']);
test('notification payload cannot include commerce or storage secrets', function (string $secret) {
    $t = SF::ticket();
    app(SupportWorkflow::class)->reply($t->seller, $t, SF::reply(), [], 'author');
    recover16();
    $data = DB::table('notification_deliveries')->pluck('payload')->implode('\n');
    expect($data)->not->toContain($secret);
})->with(['license_key', 'license_secret', 'license_key_hash', 'storage_path', 'product-private', 'signed_url', 'account_number', 'billing_address', 'webhook', 'PRIVATE STAFF']);
test('notification retention is deliberate for otherwise deletable user', function () {
    $u = User::factory()->create();
    $u->notifyNow(new MarketplaceNotification((string) Str::uuid(), ['type' => 'purchase_ready', 'title' => 'Old notice', 'message' => 'Safe', 'action_path' => '/account/orders/example', 'occurred_at' => now()->toIso8601String()]), ['database']);
    DB::table('notification_preferences')->insert(['user_id' => $u->id, 'group' => 'support', 'in_app_enabled' => true, 'email_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $u->delete();
    expect(DB::table('notifications')->count())->toBe(0)->and(DB::table('notification_preferences')->count())->toBe(0);
});
test('notification starts at deployment epoch without historic email burst', function () {
    config()->set('notifications.started_at', now()->addDays(1)->toIso8601String());
    $o = SF::order();
    recover16();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(0);
});
test('notification recovery command is safe and repeatable', function () {
    Queue::fake();
    $o = SF::order();
    $this->artisan('notifications:reconcile')->assertSuccessful();
    $this->artisan('notifications:reconcile')->assertSuccessful();
    expect(notices16($o->user, 'purchase_ready'))->toHaveCount(1);
});
test('notification registry excludes marketing security overrides and raw free form types', function () {
    expect(NT::tryFrom('marketing'))->toBeNull()->and(NT::tryFrom('password_reset'))->toBeNull()->and(NT::tryFrom('sms'))->toBeNull();
});

test('postgres notification controlled types and states are protected', function (string $field, string $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case.');
    }$o = SF::order();
    recover16();
    $id = notices16($o->user, 'purchase_ready')->first()->id;
    expect(fn () => DB::transaction(fn () => DB::table('notification_deliveries')->where('id', $id)->update([$field => $value])))->toThrow(QueryException::class);
})->with([['notification_type', 'newsletter'], ['email_status', 'sent'], ['email_status', 'anything']]);
test('postgres notification preference groups protected', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case.');
    }$u = User::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('notification_preferences')->insert(['user_id' => $u->id, 'group' => 'marketing', 'in_app_enabled' => true, 'email_enabled' => true, 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});
test('postgres notification identity immutable on direct write', function (string $field, string $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case.');
    }$o = SF::order();
    recover16();
    $id = notices16($o->user, 'purchase_ready')->first()->id;
    expect(fn () => DB::transaction(fn () => DB::table('notifications')->where('id', $id)->update([$field => $value])))->toThrow(QueryException::class);
})->with([['type', 'evil'], ['notifiable_type', 'Other'], ['data', '{"title":"forged"}']]);
test('postgres notification read timestamp cannot be overwritten', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case.');
    }$o = SF::order();
    recover16();
    $id = notices16($o->user, 'purchase_ready')->first()->id;
    $this->actingAs($o->user)->post(route('notifications.read', $id));
    expect(fn () => DB::transaction(fn () => DB::table('notifications')->where('id', $id)->update(['read_at' => null])))->toThrow(QueryException::class);
});
test('postgres logical notification dedupe unique', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case.');
    }$o = SF::order();
    recover16();
    $r = (array) DB::table('notification_deliveries')->where('user_id', $o->user_id)->first();
    $r['id'] = (string) Str::uuid();
    expect(fn () => DB::transaction(fn () => DB::table('notification_deliveries')->insert($r)))->toThrow(QueryException::class);
});
