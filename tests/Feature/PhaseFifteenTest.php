<?php

use App\Events\Support\SupportTicketCreated;
use App\Models\CommissionPolicy;
use App\Models\PayoutRequest;
use App\Models\Refund;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Support\SupportEligibility;
use App\Services\Support\SupportPresentation;
use App\Services\Support\SupportWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;
use Tests\Support\ReviewFixtures;
use Tests\Support\SupportFixtures as SF;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    ProductFileFixtures::fakeStorage();
    config()->set('support.attachment_disk', config('product_files.artifact_disk'));
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
test('support initial ticket contains real purchase initial message and audit', function () {
    $t = SF::ticket();
    expect($t->status->value)->toBe('awaiting_seller')->and($t->messages()->count())->toBe(1)->and($t->events()->count())->toBe(1);
    $this->actingAs($t->customer)->get(route('account.support.show', $t))->assertOk();
    $this->actingAs($t->seller)->get(route('author.support.show', $t))->assertOk();
});
function actor15(SupportTicket $t): User
{
    return $t->customer;
}
function staff15(): User
{
    return User::factory()->create()->assignRole('admin');
}
function transition15(SupportTicket $t, string $a, string $mode = 'customer', array $input = []): SupportTicket
{
    return app(SupportWorkflow::class)->transition($mode === 'author' ? $t->seller : ($mode === 'admin' ? staff15() : $t->customer), $t, $a, $input, $mode);
}
function reply15(SupportTicket $t, string $mode = 'customer', ?array $input = null): SupportTicket
{
    return app(SupportWorkflow::class)->reply($mode === 'author' ? $t->seller : ($mode === 'admin' ? staff15() : $t->customer), $t, $input ?? SF::reply(), [], $mode);
}
test('support guest routes require Fortify authentication', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['account.support.index', 'account.support.create', 'author.support.index', 'admin.support.index']);
test('support requires paid and consistent provisioned access', function (string $state) {
    $p = EarningFixtures::product();
    $p->offers->first()->forceFill(['support_period_days' => 180])->save();
    $o = ReviewFixtures::purchase($p, paid: false);
    if ($state !== 'unpaid') {
        $o = EntitlementFixtures::pay($o, $state === 'failed' ? 'failed' : 'paid');
    }if (in_array($state, ['entitlement', 'license'])) {
        $record = $state === 'license' ? $o->items->first()->entitlement->license : $o->items->first()->entitlement;
        $record->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    }expect(fn () => SF::ticket($o))->toThrow(ValidationException::class)->and(SupportTicket::count())->toBe(0);
})->with(['unpaid', 'failed', 'entitlement', 'license']);
test('support validates ticket content and protected identity fields', function (string $field, mixed $value) {
    $o = SF::order();
    $v = [...SF::input(), $field => $value];
    expect(fn () => app(SupportWorkflow::class)->create(User::findOrFail($o->user_id), $o->items->first(), $v))->toThrow(ValidationException::class)->and(SupportTicket::count())->toBe(0);
})->with([['category', 'refund'], ['category', 'payment_dispute'], ['priority', 'critical'], ['subject', 'x'], ['subject', str_repeat('s', 201)], ['subject', '<b>Title</b>'], ['body', ''], ['body', str_repeat('x', 10001)], ['body', '<script>alert(1)</script>'], ['body', ['array']], ['request_key', 'bad'], ['customer_user_id', 999], ['product_id', 999], ['seller_user_id', 999], ['status', 'resolved'], ['sender_user_id', 999], ['sender_type', 'admin'], ['is_internal', true], ['support_period_days', 999], ['version', '999.0'], ['paid_at', '2020-01-01'], ['last_message_at', '2099-01-01'], ['admin_locked', true]]);
test('support paid purchase belongs only to original buyer', function () {
    $o = SF::order();
    $u = User::factory()->create()->assignRole('customer');
    $this->actingAs($u)->post(route('account.support.store'), [...SF::input(), 'purchase' => $o->items->first()->entitlement->getAttribute('public_id')])->assertNotFound();
});
test('support absence zero and expiry do not invent unlimited rights', function (?int $days) {
    $o = SF::order(days: $days);
    if ($days === 1) {
        $this->travel(2)->days();
    }expect(fn () => SF::ticket($o))->toThrow(ValidationException::class);
    $this->travelBack();
})->with([null, 0, 1]);
test('support exact end boundary is exclusive', function () {
    $o = SF::order(days: 1);
    $end = app(SupportEligibility::class)->ends($o->items->first());
    $this->travelTo($end);
    expect(fn () => SF::ticket($o))->toThrow(ValidationException::class);
    $this->travelBack();
});
test('support uses historical terms and version while product changes', function () {
    $t = SF::ticket();
    $i = $t->item;
    $i->product->offers->first()->forceFill(['support_period_days' => 1])->save();
    $i->product->forceFill(['name' => 'Current revised software', 'version_label' => '99.0'])->save();
    $t->refresh();
    expect($t->getAttribute('support_period_days'))->toBe(180)->and($t->getAttribute('version'))->toBe($i->getAttribute('version'))->and($t->getAttribute('product_name'))->not->toBe('Current revised software');
});
test('support active conversation continues past support expiry but cannot reopen', function () {
    $o = SF::order(days: 1);
    $t = SF::ticket($o);
    $this->travel(2)->days();
    reply15($t, 'author');
    reply15($t);
    transition15($t, 'resolve', 'author');
    expect(fn () => transition15($t, 'reopen'))->toThrow(ValidationException::class);
    $this->travelBack();
    expect($t->messages()->count())->toBe(3);
});
test('support creation is idempotent and rejects changed retry', function () {
    $o = SF::order();
    $v = SF::input();
    $s = app(SupportWorkflow::class);
    $u = User::findOrFail($o->user_id);
    $a = $s->create($u, $o->items->first(), $v);
    $b = $s->create($u, $o->items->first(), $v);
    expect($b->id)->toBe($a->id)->and(SupportTicket::count())->toBe(1)->and($a->messages()->count())->toBe(1);
    expect(fn () => $s->create($u, $o->items->first(), [...$v, 'body' => 'Different retry']))->toThrow(ValidationException::class);
});
test('support permits separate issues for the same purchase with unique references', function () {
    $o = SF::order();
    $a = SF::ticket($o);
    $b = SF::ticket($o);
    expect($a->getAttribute('ticket_number'))->not->toBe($b->getAttribute('ticket_number'))->and(SupportTicket::count())->toBe(2);
});
test('support messages are idempotent changed retry rejected', function () {
    $t = SF::ticket();
    $v = SF::reply();
    reply15($t, 'customer', $v);
    reply15($t, 'customer', $v);
    expect($t->messages()->count())->toBe(2)->and($t->events()->count())->toBe(2);
    expect(fn () => reply15($t, 'customer', [...$v, 'body' => 'Different retry']))->toThrow(ValidationException::class);
});
test('support lifecycle replies resolution reopening and terminal close', function () {
    $t = SF::ticket();
    expect(reply15($t, 'author')->status->value)->toBe('awaiting_customer');
    expect(reply15($t)->status->value)->toBe('awaiting_seller');
    expect(transition15($t, 'resolve', 'author')->status->value)->toBe('resolved');
    expect(transition15($t, 'reopen')->status->value)->toBe('awaiting_seller');
    transition15($t, 'resolve', 'author');
    expect(transition15($t, 'close')->status->value)->toBe('closed');
    expect(fn () => transition15($t, 'reopen'))->toThrow(ValidationException::class)->and(fn () => reply15($t))->toThrow(ValidationException::class);
});
test('support participant capabilities reject invalid actions', function (string $action, string $mode) {
    $t = SF::ticket();
    expect(fn () => transition15($t, $action, $mode, ['priority' => 'high', 'reason' => 'Meaningful reason']))->toThrow($action === 'close' && $mode === 'customer' || $action === 'reopen' ? ValidationException::class : HttpException::class);
})->with([['close', 'customer'], ['close', 'author'], ['resolve', 'customer'], ['priority', 'author'], ['priority', 'customer'], ['reopen', 'customer']]);
test('support message immutability is enforced in models', function (string $model, string $action) {
    $t = SF::ticket();
    $m = $model === 'message' ? $t->messages()->first() : $t->events()->first();
    expect(fn () => $action === 'delete' ? $m->delete() : $m->forceFill(['body' => 'Rewritten content'])->save())->toThrow(LogicException::class);
})->with([['message', 'edit'], ['message', 'delete'], ['event', 'edit'], ['event', 'delete']]);
test('support sent messages have no edit or delete endpoints', function (string $method) {
    $t = SF::ticket();
    $url = '/account/support/'.$t->getAttribute('public_uuid').'/messages/'.$t->messages()->first()->getAttribute('public_uuid');
    $this->actingAs($t->customer)->{$method}($url)->assertNotFound();
})->with(['put', 'delete']);
test('support ownership protects all participant endpoints', function (string $space, string $action) {
    $t = SF::ticket();
    $u = User::factory()->create()->assignRole($space === 'author' ? 'author' : 'customer');
    $this->actingAs($u);
    if ($action === 'show') {
        $this->get(route($space.'.support.show', $t))->assertNotFound();
    } else {
        $this->post(route($space.'.support.'.$action, $t), SF::reply())->assertNotFound();
    }
})->with([['account', 'show'], ['account', 'messages'], ['account', 'close'], ['account', 'reopen'], ['author', 'show'], ['author', 'messages'], ['author', 'resolve'], ['author', 'escalate']]);
test('support read only permission does not allow mutation', function (string $action) {
    $t = SF::ticket();
    $u = User::factory()->create();
    $u->givePermissionTo('support.view');
    $this->actingAs($u)->get(route('admin.support.show', $t))->assertOk();
    $this->post(route('admin.support.'.$action, $t), [...SF::reply(), 'reason' => 'Read only must not mutate', 'priority' => 'high'])->assertForbidden();
})->with(['messages', 'notes', 'close', 'priority', 'resolve', 'escalate']);
test('support staff internal notes are server excluded for participants', function () {
    $t = SF::ticket();
    $admin = staff15();
    app(SupportWorkflow::class)->reply($admin, $t, SF::reply('PRIVATE INTERNAL STAFF NOTE'), [], 'admin', true);
    foreach (['customer' => $t->customer, 'author' => $t->seller] as $mode => $u) {
        $v = app(SupportPresentation::class)->detail($t->fresh(), $u, $mode);
        expect(json_encode($v))->not->toContain('PRIVATE INTERNAL STAFF NOTE')->not->toContain('internal_note_added');
    }$v = app(SupportPresentation::class)->detail($t->fresh(), $admin, 'admin');
    expect(json_encode($v))->toContain('PRIVATE INTERNAL STAFF NOTE');
});
test('support staff can intervene without impersonating seller', function () {
    $t = SF::ticket();
    $u = staff15();
    app(SupportWorkflow::class)->reply($u, $t, SF::reply('Staff assistance for installation'), [], 'admin');
    $v = app(SupportPresentation::class)->detail($t->fresh(), $t->customer, 'customer');
    expect($v['messages']->items()[1]['sender'])->toBe('Templaara Support');
    transition15($t, 'priority', 'admin', ['priority' => 'high']);
    transition15($t, 'escalate', 'admin', ['reason' => 'Additional technical assistance needed']);
    transition15($t, 'deescalate', 'admin', ['reason' => 'Technical issue addressed']);
    transition15($t, 'close', 'admin', ['reason' => 'Administrative terminal closure']);
    expect($t->fresh()->getAttribute('admin_locked'))->toBeTrue()->and($t->events()->pluck('type')->all())->toContain('priority_changed', 'escalated', 'deescalated', 'closed')->and(Refund::count())->toBe(0);
});
test('support official products use real staff and no fake seller', function () {
    $t = SF::ticket(SF::order(official: true));
    expect($t->getAttribute('seller_user_id'))->toBeNull();
    $u = staff15();
    app(SupportWorkflow::class)->reply($u, $t, SF::reply(), [], 'admin');
    transition15($t, 'resolve', 'admin');
    $v = app(SupportPresentation::class)->detail($t->fresh(), $t->customer, 'customer');
    expect($v['seller'])->toBe('Templaara Support')->and($v['messages']->items()[1]['sender'])->toBe('Templaara Support');
});
test('support attachments validate useful file formats', function (string $extension) {
    $file = in_array($extension, ['png', 'jpg', 'webp']) ? UploadedFile::fake()->image('screenshot.'.$extension) : UploadedFile::fake()->createWithContent('diagnostic.'.$extension, $extension === 'pdf' ? "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF" : 'Plain installation log for support');
    $t = SF::ticket(files: [$file]);
    $a = SupportAttachment::firstOrFail();
    expect($a->getAttribute('disk'))->toBe(config('support.attachment_disk'));
    $this->actingAs($t->customer)->get(route('account.support.attachments.download', $a))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    $this->actingAs($t->seller)->get(route('author.support.attachments.download', $a))->assertOk();
})->with(['png', 'jpg', 'webp', 'pdf', 'txt', 'log']);
test('support attachments reject dangerous files or oversized collections', function (string $case) {
    $files = match ($case) {
        'size' => [UploadedFile::fake()->create('too-large.txt', 10241, 'text/plain')],'count' => array_fill(0, 6, UploadedFile::fake()->image('image.png')),'mismatch' => [UploadedFile::fake()->createWithContent('fake.png', 'ordinary text')],'pdf' => [UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/JavaScript dangerous")],'script_text' => [UploadedFile::fake()->createWithContent('script.txt', "#!/bin/bash\necho forbidden")],default => [UploadedFile::fake()->createWithContent('unsafe.'.$case, '<script>alert(1)</script>')]
    };
    expect(fn () => SF::ticket(files: $files))->toThrow(ValidationException::class)->and(SupportTicket::count())->toBe(0);
})->with(['exe', 'php', 'js', 'sh', 'html', 'svg', 'mismatch', 'size', 'count', 'pdf', 'script_text']);
test('support attachments deny IDOR and keep metadata private', function () {
    $t = SF::ticket(files: [UploadedFile::fake()->image('screenshot.png')]);
    $a = SupportAttachment::firstOrFail();
    $u = User::factory()->create()->assignRole('customer');
    $this->actingAs($u)->get(route('account.support.attachments.download', $a))->assertNotFound();
    $this->actingAs(User::factory()->create()->assignRole('author'))->get(route('author.support.attachments.download', $a))->assertNotFound();
    $v = json_encode(app(SupportPresentation::class)->detail($t, $t->seller, 'author'));
    foreach (['checksum', 'storage_path', '"disk"', $a->getAttribute('path'), $t->customer->email, $t->item->order->getAttribute('order_number'), 'billing', 'license_secret', 'payout'] as $key) {
        expect($v)->not->toContain($key);
    }
});
test('support internal note attachments are staff only', function () {
    $t = SF::ticket();
    app(SupportWorkflow::class)->reply(staff15(), $t, SF::reply('Private diagnostic information'), [UploadedFile::fake()->image('internal.png')], 'admin', true);
    $a = SupportAttachment::firstOrFail();
    $this->actingAs($t->customer)->get(route('account.support.attachments.download', $a))->assertNotFound();
    $this->actingAs($t->seller)->get(route('author.support.attachments.download', $a))->assertNotFound();
    $this->actingAs(staff15())->get(route('admin.support.attachments.download', $a))->assertOk();
});
test('support completed refund closes exact purchase and retains files history', function () {
    $o = SF::order();
    $t = SF::ticket($o, [UploadedFile::fake()->image('screen.png')]);
    RefundFixtures::complete(RefundFixtures::request($o));
    $t->refresh();
    expect($t->status->value)->toBe('closed')->and($t->getAttribute('admin_locked'))->toBeTrue()->and($t->messages()->count())->toBe(1)->and(SupportAttachment::count())->toBe(1)->and($t->events()->pluck('type')->all())->toContain('purchase_refunded');
    expect(fn () => reply15($t))->toThrow(ValidationException::class)->and(fn () => transition15($t, 'reopen'))->toThrow(ValidationException::class)->and(fn () => SF::ticket($o))->toThrow(ValidationException::class);
    $this->actingAs($t->customer)->get(route('account.support.attachments.download', SupportAttachment::first()))->assertOk();
    expect($t->item->entitlement->fresh()->status->value)->toBe('revoked')->and($t->item->entitlement->license->fresh()->status->value)->toBe('revoked');
});
test('support non completed refunds do not terminate conversation', function (string $state) {
    $o = SF::order();
    $t = SF::ticket($o);
    $f = RefundFixtures::request($o);
    if ($state === 'approved') {
        app(RefundWorkflow::class)->transition(staff15(), $f, 'approve', admin: true);
    }if (in_array($state, ['processing', 'failed'])) {
        $a = RefundFixtures::process($f);
    }if ($state === 'failed') {
        app(RefundWorkflow::class)->transition($a, $f, 'fail', ['reason' => 'External refund failed'], true);
    }if ($state === 'cancelled') {
        app(RefundWorkflow::class)->transition($t->customer, $f, 'cancel');
    }reply15($t);
    expect($t->fresh()->status->value)->toBe('awaiting_seller');
    SF::ticket($o);
})->with(['requested', 'approved', 'processing', 'failed', 'cancelled']);
test('support another item refund does not close or rebind tickets', function () {
    $t = SF::ticket();
    $other = ReviewFixtures::purchase($t->item->product, $t->customer);
    RefundFixtures::complete(RefundFixtures::request($other));
    expect($t->fresh()->status->value)->toBe('awaiting_seller')->and($t->fresh()->getAttribute('order_item_id'))->toBe($t->item->id);
});
test('support read state uses sequences so same second messages are not lost', function () {
    $t = SF::ticket();
    $this->actingAs($t->seller)->get(route('author.support.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('records.data.0.unread', 1));
    $this->get(route('author.support.show', $t))->assertOk();
    reply15($t);
    $this->get(route('author.support.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('records.data.0.unread', 1));
    app(SupportWorkflow::class)->reply(staff15(), $t, SF::reply('Private note'), [], 'admin', true);
    $this->get(route('author.support.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('records.data.0.unread', 1));
    reply15($t, 'author');
    $this->actingAs($t->customer)->get(route('account.support.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('records.data.0.unread', 1));
});
test('support retains account and product history', function () {
    $t = SF::ticket();
    $this->actingAs($t->customer)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(fn () => DB::transaction(fn () => $t->product->delete()))->toThrow(QueryException::class);
    expect(fn () => $t->delete())->toThrow(LogicException::class);
});
test('support after commit events contain safe IDs and rollback emits nothing', function () {
    Event::fake([SupportTicketCreated::class]);
    $o = SF::order();
    try {
        DB::transaction(function () use ($o) {
            SF::ticket($o);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException $e) {
    }Event::assertNotDispatched(SupportTicketCreated::class);
    $t = SF::ticket($o);
    Event::assertDispatched(SupportTicketCreated::class, fn ($e) => array_keys(get_object_vars($e)) === ['ticketId']);
});
test('support multi role buyer and seller remain authorized', function () {
    $t = SF::ticket();
    $t->customer->assignRole('author');
    $t->seller->assignRole('customer');
    reply15($t);
    reply15($t, 'author');
    expect($t->messages()->count())->toBe(3);
});
test('support mutations do not change purchased commercial or financial records', function () {
    $t = SF::ticket();
    $before = [$t->item->getAttributes(), $t->item->order->getAttributes(), $t->item->entitlement->getAttributes(), $t->item->entitlement->license->getAttributes(), $t->item->earning->getAttributes()];
    reply15($t);
    reply15($t, 'author');
    transition15($t, 'resolve', 'author');
    $after = [$t->item->fresh()->getAttributes(), $t->item->order->fresh()->getAttributes(), $t->item->entitlement->fresh()->getAttributes(), $t->item->entitlement->license->fresh()->getAttributes(), $t->item->earning->fresh()->getAttributes()];
    expect($after)->toBe($before)->and(Refund::count())->toBe(0)->and(PayoutRequest::count())->toBe(0);
});
function reject15(callable $fn): void
{
    expect(fn () => DB::transaction(function () use ($fn) {
        $fn();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
}
test('postgresql support controlled lifecycle and commercial snapshot cannot be forged', function (string $field, mixed $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL support integrity');
    }$t = SF::ticket();
    reject15(fn () => DB::table('support_tickets')->where('id', $t->id)->update([$field => $value, 'event_sequence' => 2]));
})->with([['customer_user_id', 999999], ['order_item_id', 999999], ['product_id', 999999], ['seller_user_id', 999999], ['version', 'forged'], ['support_period_days', 999], ['paid_at', '2020-01-01'], ['status', 'invalid'], ['status', 'closed'], ['status', 'resolved'], ['priority', 'critical'], ['category', 'refund'], ['subject', ''], ['admin_locked', true], ['customer_last_read_sequence', 999]]);
test('postgresql support cross customer product and seller purchase inserts fail', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL support integrity');
    }$t = SF::ticket();
    $other = SF::ticket();
    $data = $t->getAttributes();
    unset($data['id']);
    $data['public_uuid'] = (string) Str::uuid();
    $data['ticket_number'] = 'TMP-SUP-TEST-'.Str::ulid();
    $data['request_key'] = (string) Str::uuid();
    $data[match ($case) {
        'customer' => 'customer_user_id','seller' => 'seller_user_id',default => 'product_id'
    }] = $other->getAttribute(match ($case) {
        'customer' => 'customer_user_id','seller' => 'seller_user_id',default => 'product_id'
    });
    reject15(fn () => DB::table('support_tickets')->insert($data));
})->with(['customer', 'product', 'seller']);
test('postgresql retains all support history against direct mutation or deletion', function (string $table, string $operation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL support integrity');
    }$t = SF::ticket(files: [UploadedFile::fake()->image('retained.png')]);
    reject15(function () use ($table, $operation) {
        $q = DB::table($table);
        if ($operation === 'delete') {
            $q->delete();
        } else {
            $q->update(match ($table) {
                'support_messages' => ['body' => 'Changed'], 'support_ticket_events' => ['reason' => 'Changed'],default => ['path' => 'support/changed.png']
            });
        }
    });
})->with([['support_tickets', 'delete'], ['support_messages', 'delete'], ['support_messages', 'update'], ['support_ticket_events', 'delete'], ['support_ticket_events', 'update'], ['support_attachments', 'delete'], ['support_attachments', 'update']]);
test('postgresql support no empty ticket or unaudited message committed', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL support integrity');
    }$t = SF::ticket();
    reject15(function () use ($t, $case) {
        if ($case === 'ticket') {
            $v = $t->getAttributes();
            unset($v['id']);
            $v['public_uuid'] = (string) Str::uuid();
            $v['ticket_number'] = 'TMP-SUP-TEST-'.Str::ulid();
            $v['request_key'] = (string) Str::uuid();
            DB::table('support_tickets')->insert($v);
        } else {
            $m = $t->messages()->first()->getAttributes();
            unset($m['id']);
            $m['public_uuid'] = (string) Str::uuid();
            $m['request_key'] = (string) Str::uuid();
            $m['sequence'] = 2;
            DB::table('support_tickets')->where('id', $t->id)->update(['event_sequence' => 2]);
            DB::table('support_messages')->insert($m);
        }
    });
})->with(['ticket', 'message']);
test('postgresql support idempotency unique and attachment sender enforced', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL support integrity');
    }$t = SF::ticket(files: [UploadedFile::fake()->image('image.png')]);
    reject15(function () use ($t, $case) {
        if ($case === 'attachment') {
            $v = SupportAttachment::first()->getAttributes();
            unset($v['id']);
            $v['public_uuid'] = (string) Str::uuid();
            $v['path'] = 'support/'.$v['public_uuid'].'.png';
            $v['uploader_user_id'] = $t->seller->id;
            DB::table('support_attachments')->insert($v);
        } else {
            $v = $t->getAttributes();
            unset($v['id']);
            $v['public_uuid'] = (string) Str::uuid();
            $v['ticket_number'] = 'TMP-SUP-TEST-'.Str::ulid();
            DB::table('support_tickets')->insert($v);
        }
    });
})->with(['attachment', 'duplicate']);
test('support queues paginate scoped filters with truthful metrics', function () {
    $o = SF::order();
    for ($i = 0; $i < 16; $i++) {
        SF::ticket($o);
    }SF::ticket();
    $u = User::findOrFail($o->user_id);
    $this->actingAs($u)->get(route('account.support.index', ['status' => 'awaiting_seller']))->assertInertia(fn (AssertableInertia $p) => $p->has('records.data', 15)->where('records.total', 16)->where('records.next_page_url', fn ($url) => str_contains($url, 'status=awaiting_seller')));
    $seller = $o->items->first()->product->owner;
    $this->actingAs($seller)->get(route('author.support.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('metrics.open', fn ($n) => (int) $n === 16)->where('metrics.awaiting_seller', fn ($n) => (int) $n === 16));
});
test('support entry points expose exact purchased item instead of latest purchase', function () {
    $t = SF::ticket();
    $this->actingAs($t->customer)->get(route('library.show', $t->item->entitlement))->assertInertia(fn (AssertableInertia $p) => $p->where('purchase.support_entry.url', route('account.support.create', ['purchase' => $t->item->entitlement->getAttribute('public_id')])));
    $this->get(route('commerce.orders.show', $t->item->order))->assertInertia(fn (AssertableInertia $p) => $p->where('order.items.0.support_entry.url', route('account.support.create', ['purchase' => $t->item->entitlement->getAttribute('public_id')])));
    $this->get('/products/'.$t->product->slug)->assertInertia(fn (AssertableInertia $p) => $p->where('support_entry.url', route('account.support.create', ['product' => $t->product->slug])));
});
