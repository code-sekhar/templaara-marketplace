<?php

use App\Enums\EntitlementStatus;
use App\Enums\RefundStatus;
use App\Events\Refunds\RefundApproved;
use App\Events\Refunds\RefundCancelled;
use App\Events\Refunds\RefundCompleted;
use App\Events\Refunds\RefundFailed;
use App\Events\Refunds\RefundProcessing;
use App\Events\Refunds\RefundRequested;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\AuthorPayoutMethod;
use App\Models\CommissionPolicy;
use App\Models\LicenseActivation;
use App\Models\Refund;
use App\Models\RefundCompensation;
use App\Models\RefundEvent;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\Commerce\PaymentLifecycle;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Entitlements\LicenseActivationService;
use App\Services\Entitlements\PurchaseAccess;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutWorkflow;
use App\Services\Refunds\ReconcileRefunds;
use App\Services\Refunds\RefundPresentation;
use App\Services\Refunds\RefundWindow;
use App\Services\Refunds\RefundWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EntitlementFixtures;
use Tests\Support\PayoutFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures as RF;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('refunds.window_days', '7');
});

test('customer requests own paid item refund with immutable exact commercial terms', function () {
    $o = RF::order();
    $r = RF::request($o);
    expect($r->status)->toBe(RefundStatus::Requested)->and($r->getAttribute('amount'))->toBe('5000.00')->and($r->items->first()->getAttribute('commission_amount'))->toBe('1000.00')->and($r->items->first()->getAttribute('author_amount'))->toBe('4000.00')->and($r->getAttribute('reference'))->toStartWith('TMP-REF-');
});
test('unpaid failed or cancelled orders cannot enter refund flow', function (string $state) {
    $o = EntitlementFixtures::order(false);
    if ($state === 'failed') {
        $o = EntitlementFixtures::pay($o, 'failed');
    }if ($state === 'cancelled') {
        app(PaymentLifecycle::class)->cancel(User::findOrFail($o->user_id), $o);
    }expect(fn () => app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, RF::input($o)))->toThrow(ValidationException::class);
})->with(['pending', 'failed', 'cancelled']);
test('customer cannot request view or cancel another customer refund', function (string $action) {
    $o = RF::order();
    $r = RF::request($o);
    $this->actingAs(User::factory()->create()->assignRole('customer'));
    (match ($action) {
        'request' => $this->post(route('account.refunds.store', $o), RF::input($o)),'view' => $this->get(route('account.refunds.show', $r)),'cancel' => $this->post(route('account.refunds.cancel', $r)),'form' => $this->get(route('account.refunds.create', $o))
    })->assertNotFound();
})->with(['request', 'view', 'cancel', 'form']);
test('request ignores browser financial and actor forgeries', function (string $field, mixed $value) {
    $o = RF::order();
    $buyer = User::findOrFail($o->user_id);
    $this->actingAs($buyer)->post(route('account.refunds.store', $o), [...RF::input($o), $field => $value])->assertRedirect();
    $r = Refund::first();
    expect($r->getAttribute('amount'))->toBe('5000.00')->and($r->getAttribute('currency'))->toBe('INR')->and($r->customer_user_id)->toBe($buyer->id)->and($r->getAttribute('requested_by'))->toBe($buyer->id)->and($r->status)->toBe(RefundStatus::Requested);
})->with([['amount', '0.01'], ['currency', 'USD'], ['customer_user_id', 999], ['requested_by', 999], ['status', 'completed'], ['author_amount', '0.00']]);
test('invalid reason and item selections are rejected', function (string $case) {
    $o = RF::order();
    $input = RF::input($o);
    $input = match ($case) {
        'reason' => [...$input, 'reason_code' => 'invented'],'empty' => [...$input, 'items' => []],'unknown' => [...$input, 'items' => [999999]],'duplicate' => [...$input, 'items' => [$o->items->first()->id, $o->items->first()->id]],'key' => [...$input, 'request_key' => 'bad']
    };
    expect(fn () => app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, $input))->toThrow(ValidationException::class);
})->with(['reason', 'empty', 'unknown', 'duplicate', 'key']);
test('window policy is snapshotted and enforced without production fallback', function () {
    $o = RF::order();
    config()->set('refunds.window_days', '14');
    $r = RF::request($o);
    // Phase 18 retains the purchase-time policy; later configuration affects new Orders.
    expect($r->getAttribute('window_days'))->toBe(7);
    expect(RF::request(RF::order())->getAttribute('window_days'))->toBe(14);
    $this->travel(15)->days();
    expect(fn () => RF::request(RF::order()))->not->toThrow(ValidationException::class);
    expect(fn () => app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, RF::input($o)))->toThrow(ValidationException::class);
    $this->travelBack();
    app()->instance('env', 'production');
    config()->set('refunds.window_days', null);
    expect(fn () => app(RefundWindow::class)->days())->toThrow(ValidationException::class);
    app()->instance('env', 'testing');
});
test('same idempotency key replays without duplicate item claim', function () {
    $o = RF::order();
    $v = RF::input($o);
    $s = app(RefundWorkflow::class);
    $u = User::findOrFail($o->user_id);
    $a = $s->request($u, $o, $v);
    $b = $s->request($u, $o, $v);
    expect($a->id)->toBe($b->id)->and(Refund::count())->toBe(1);
    expect(fn () => $s->request($u, $o, [...$v, 'reason_code' => 'other']))->toThrow(ValidationException::class);
});
test('distinct active duplicate request cannot claim item again', function () {
    $o = RF::order();
    RF::request($o);
    expect(fn () => RF::request($o))->toThrow(ValidationException::class);
});
test('customer reviewers and authors lack admin refund access', function (string $role) {
    $r = RF::request();
    $this->actingAs(User::factory()->create()->assignRole($role))->get(route('admin.refunds.index'))->assertForbidden();
    $this->post(route('admin.refunds.approve', $r))->assertForbidden();
})->with(['customer', 'reviewer', 'author']);
test('read permission does not permit refund mutations', function () {
    $r = RF::request();
    $u = User::factory()->create()->givePermissionTo('refunds.view');
    $this->actingAs($u)->get(route('admin.refunds.show', $r))->assertOk();
    $this->post(route('admin.refunds.approve', $r))->assertForbidden();
});
test('approval processing failure and cancellation preserve purchase access and earnings', function (string $state) {
    $o = RF::order();
    $r = RF::request($o);
    $a = User::factory()->create()->assignRole('admin');
    $s = app(RefundWorkflow::class);
    if (in_array($state, ['approved', 'processing', 'failed'])) {
        $s->transition($a, $r, 'approve', admin: true);
    }if (in_array($state, ['processing', 'failed'])) {
        $s->transition($a, $r, 'process', admin: true);
    }if ($state === 'failed') {
        $s->transition($a, $r, 'fail', ['reason' => 'External refund did not succeed'], true);
    }if ($state === 'cancelled') {
        $s->transition(User::findOrFail($o->user_id), $r, 'cancel');
    }$e = $o->items->first()->entitlement->fresh();
    expect(app(PurchaseAccess::class)->valid($e))->toBeTrue()->and(RefundCompensation::count())->toBe(0)->and(AuthorEarningLedgerEntry::where('type', 'refund_reversal')->count())->toBe(0);
})->with(['approved', 'processing', 'failed', 'cancelled']);
test('completion requires external reference and failure requires reason', function (string $action) {
    $r = RF::request();
    $a = RF::process($r);
    expect(fn () => app(RefundWorkflow::class)->transition($a, $r, $action, admin: true))->toThrow(ValidationException::class);
})->with(['complete', 'fail']);
test('completion atomically revokes access preserves history and reverses pending economics', function () {
    $o = RF::order();
    $item = $o->items->first();
    $e = $item->earning;
    $facts = $e->getAttributes();
    $r = RF::request($o);
    RF::complete($r);
    $e->refresh();
    expect($e->getAttributes())->toBe($facts);
    $ent = $item->entitlement->fresh();
    expect($ent->status)->toBe(EntitlementStatus::Revoked)->and($ent->license->status->value)->toBe('revoked')->and(app(PurchaseAccess::class)->valid($ent))->toBeFalse();
    $b = app(FinancialBalances::class)->all($e->author_user_id, true)[0];
    expect($b)->toMatchArray(['earned' => '4000.00', 'net_earned' => '0.00', 'pending' => '0.00', 'available' => '0.00', 'refund_adjustment' => '4000.00', 'gross' => '5000.00', 'gross_refunded' => '5000.00', 'net_sales' => '0.00', 'commission' => '1000.00', 'commission_reversed' => '1000.00', 'net_commission' => '0.00']);
    expect(app(ReleaseEarnings::class)->release($e))->toBeFalse();
});
test('available earning refund removes available balance with one compensation', function () {
    $o = RF::order(true);
    $e = $o->items->first()->earning;
    $r = RF::request($o);
    $a = RF::complete($r);
    app(RefundWorkflow::class)->transition($a, $r, 'complete', ['external_reference' => 'EXTERNAL-REFUND-TEST'], true);
    expect(RefundCompensation::count())->toBe(1)->and(AuthorEarningLedgerEntry::where('type', 'refund_reversal')->count())->toBe(1)->and(app(FinancialBalances::class)->available($e->author_user_id, 'INR'))->toBe('0.00');
});
test('completed refund is terminal and item cannot be requested again', function (string $action) {
    $o = RF::order();
    $r = RF::request($o);
    $a = RF::complete($r);
    expect(fn () => app(RefundWorkflow::class)->transition($a, $r, $action, ['reason' => 'Do not reopen history'], true))->toThrow(ValidationException::class);
    expect(fn () => RF::request($o))->toThrow(ValidationException::class);
})->with(['approve', 'process', 'fail', 'cancel']);
test('failed and cancelled attempts retain history but allow new reviewed attempt', function (string $state) {
    $o = RF::order();
    $r = RF::request($o);
    $s = app(RefundWorkflow::class);
    if ($state === 'failed') {
        $a = RF::process($r);
        $s->transition($a, $r, 'fail', ['reason' => 'External attempt failed'], true);
    } else {
        $s->transition(User::findOrFail($o->user_id), $r, 'cancel');
    }$next = RF::request($o);
    expect($next->id)->not->toBe($r->id)->and(Refund::count())->toBe(2);
})->with(['failed', 'cancelled']);
test('customer cannot cancel approved or processing refund', function (string $state) {
    $r = RF::request();
    $a = User::factory()->create()->assignRole('admin');
    app(RefundWorkflow::class)->transition($a, $r, 'approve', admin: true);
    if ($state === 'processing') {
        app(RefundWorkflow::class)->transition($a, $r, 'process', admin: true);
    }expect(fn () => app(RefundWorkflow::class)->transition(User::findOrFail($r->customer_user_id), $r, 'cancel'))->toThrow(ValidationException::class);
})->with(['approved', 'processing']);
test('admin cancellation before processing clears claim with reason', function (string $state) {
    $r = RF::request();
    $a = User::factory()->create()->assignRole('admin');
    $s = app(RefundWorkflow::class);
    if ($state === 'approved') {
        $s->transition($a, $r, 'approve', admin: true);
    }$s->transition($a, $r, 'cancel', ['reason' => 'Customer requested withdrawal'], true);
    expect(DB::table('purchase_refund_states')->value('active_refund_id'))->toBeNull();
})->with(['requested', 'approved']);
test('unpaid author payout blocks refund without silently releasing reservations', function (string $state) {
    $o = RF::order(true);
    $e = $o->items->first()->earning;
    $p = PayoutFixtures::request($e->author, '2000.00');
    $r = RF::request($o);
    $a = User::factory()->create()->assignRole('admin');
    $pw = app(PayoutWorkflow::class);
    if ($state !== 'requested') {
        $pw->transition($a, $p, 'approve', asAdmin: true);
    }if ($state === 'processing') {
        $pw->transition($a, $p, 'process', asAdmin: true);
    }app(RefundWorkflow::class)->transition($a, $r, 'approve', admin: true);
    expect(fn () => app(RefundWorkflow::class)->transition($a, $r, 'process', admin: true))->toThrow(ValidationException::class);
    expect(RefundCompensation::count())->toBe(0)->and(app(FinancialBalances::class)->all($e->author_user_id)[0]['reserved'])->toBe('2000.00');
})->with(['requested', 'approved', 'processing']);
test('post payout refund produces recoverable balance and future earnings offset it', function () {
    $o = RF::order(true);
    $e = $o->items->first()->earning;
    $p = PayoutFixtures::request($e->author, '4000.00');
    $a = PayoutFixtures::process($p);
    app(PayoutWorkflow::class)->transition($a, $p, 'pay', ['external_reference' => 'BANK-PAID'], true);
    $facts = $p->fresh()->getAttributes();
    $r = RF::request($o);
    RF::complete($r, $a);
    $b = app(FinancialBalances::class)->all($e->author_user_id)[0];
    expect($b)->toMatchArray(['outstanding_adjustment' => '4000.00', 'net_position' => '-4000.00', 'available' => '0.00', 'paid' => '4000.00']);
    expect($p->fresh()->getAttributes())->toBe($facts);
    PayoutFixtures::available($e->author, 'INR', '12500.00');
    expect(app(FinancialBalances::class)->available($e->author_user_id, 'INR'))->toBe('6000.00');
    $method = AuthorPayoutMethod::first();
    expect(fn () => PayoutFixtures::request($e->author, '7000.00', $method))->toThrow(ValidationException::class);
    $next = PayoutFixtures::request($e->author, '6000.00', $method);
    expect($next->allocations->first()->getAttribute('author_earning_id'))->not->toBe($e->id);
});
test('official refund creates no fake author earning or adjustment', function () {
    $o = EntitlementFixtures::order();
    $r = RF::request($o);
    RF::complete($r);
    expect(AuthorEarning::count())->toBe(0)->and(AuthorEarningLedgerEntry::where('type', 'refund_reversal')->count())->toBe(0)->and(RefundCompensation::first()->getAttribute('author_user_id'))->toBeNull()->and($o->items->first()->entitlement->fresh()->status)->toBe(EntitlementStatus::Revoked);
});
test('multi author order item refunds isolate effects and progress partial to full', function () {
    $o = RF::mixed();
    $s = app(RefundWorkflow::class);
    $buyer = User::findOrFail($o->user_id);
    $items = $o->items()->orderBy('id')->get();
    $first = $s->request($buyer, $o, RF::input($o, [$items[0]->id]));
    RF::complete($first);
    expect(app(RefundPresentation::class)->orderStatus($o))->toBe('partial')->and($items[1]->entitlement->fresh()->status)->toBe(EntitlementStatus::Active)->and($items[1]->earning->ledger()->where('type', 'refund_reversal')->exists())->toBeFalse();
    $remaining = $s->request($buyer, $o, RF::input($o, [$items[1]->id, $items[2]->id]));
    RF::complete($remaining);
    expect(app(RefundPresentation::class)->orderStatus($o))->toBe('full')->and($o->fresh()->payment_status->value)->toBe('paid')->and($o->fresh()->status->value)->toBe('completed');
});
test('download and activation facts do not automatically deny refunds and revoked key cannot activate or validate', function () {
    $o = RF::order();
    $e = $o->items->first()->entitlement;
    $input = ['license_key' => $e->license->getAttribute('license_secret'), 'product' => $e->item->product->getAttribute('public_uuid'), 'installation_id' => (string) Str::uuid()];
    $s = app(LicenseActivationService::class);
    expect($s->execute('activate', $input)['valid'])->toBeTrue();
    $this->actingAs(User::findOrFail($o->user_id))->get(route('library.download', $e))->assertOk();
    $r = RF::request($o);
    expect($r->items->first()->getAttribute('review_facts'))->toMatchArray(['activation_exists' => true, 'download_access_issued' => true]);
    RF::complete($r);
    foreach (['activate', 'validate'] as $a) {
        expect($s->execute($a, $input))->toBe(['valid' => false, 'code' => 'LICENSE_INACTIVE']);
    }expect(LicenseActivation::count())->toBe(1);
    $this->get(route('library.download', $e))->assertForbidden();
});
test('historical library retains exact purchased version and safe refund data', function () {
    $o = RF::order();
    $e = $o->items->first()->entitlement;
    $r = RF::request($o);
    RF::complete($r);
    $this->actingAs(User::findOrFail($o->user_id))->get(route('library.show', $e))->assertOk()->assertInertia(fn (Assert $p) => $p->where('purchase.status', 'revoked')->where('purchase.version', $e->item->getAttribute('version'))->where('purchase.download_available', false)->where('purchase.license.key', null)->where('purchase.refund.reference', $r->getAttribute('reference')));
});
test('author adjustment presentation and admin refund serialization contain no commercial secrets', function () {
    $o = RF::order();
    $r = RF::request($o);
    RF::complete($r);
    $data = app(RefundPresentation::class)->detail($r, true);
    $json = json_encode($data);
    foreach (['license_secret', 'license_key_hash', 'billing_email', 'address_line_1', 'provider_payload', 'artifact_path', 'method_snapshot_encrypted'] as $secret) {
        expect($json)->not->toContain($secret);
    }expect($data['items'][0]['author_amount'])->toBe('4000.00');
});
test('currencies remain separate after USD refund', function () {
    $o = RF::order(true, 'USD');
    $e = $o->items->first()->earning;
    PayoutFixtures::available($e->author, 'INR');
    $r = RF::request($o);
    RF::complete($r);
    $b = app(FinancialBalances::class)->all($e->author_user_id);
    expect($b[0])->toMatchArray(['currency' => 'INR', 'available' => '5000.00'])->and($b[1])->toMatchArray(['currency' => 'USD', 'available' => '0.00']);
});
test('model refund items events financial compensation and completed refund cannot be edited or deleted', function (string $model) {
    $r = RF::request();
    RF::complete($r);
    $row = match ($model) {
        'refund' => $r->fresh(),'item' => RefundItem::first(),'event' => RefundEvent::first(),'compensation' => RefundCompensation::first()
    };
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(fn () => $row->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class);
})->with(['refund', 'item', 'event', 'compensation']);
test('refund actors come from authenticated user and account retention remains enforced', function () {
    $r = RF::request();
    $a = User::factory()->create()->assignRole('admin');
    $this->actingAs($a)->post(route('admin.refunds.approve', $r), ['reviewed_by' => 999, 'reviewed_at' => '2000-01-01'])->assertRedirect();
    expect($r->fresh()->getAttribute('reviewed_by'))->toBe($a->id);
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
});
test('refund event emits after commit and rollback leaves no claims', function () {
    $o = RF::order();
    Event::fake([RefundRequested::class]);
    expect(fn () => DB::transaction(function () use ($o) {
        RF::request($o);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    Event::assertNotDispatched(RefundRequested::class);
    expect(Refund::count())->toBe(0)->and(DB::table('purchase_refund_states')->count())->toBe(0);
    $r = RF::request($o);
    Event::assertDispatched(RefundRequested::class, fn ($v) => $v->refundId === $r->id && array_keys(get_object_vars($v)) === ['refundId']);
});
function reject13(callable $write): void
{
    expect(fn () => DB::transaction(function () use ($write) {
        $write();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
}
test('postgresql rejects refund economic identity or illegal lifecycle changes', function (string $field, mixed $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RF::request();
    reject13(fn () => DB::table('refunds')->where('id', $r->id)->update([$field => $value]));
})->with([['amount', '-1.00'], ['currency', 'USD'], ['order_id', 999999], ['customer_user_id', 999999], ['window_days', 90], ['status', 'completed']]);
test('postgresql retains refund financial and audit history', function (string $table, string $operation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RF::request();
    RF::complete($r);
    reject13(function () use ($table, $operation) {
        $id = DB::table($table)->value('id');
        $q = DB::table($table)->where('id', $id);
        $operation === 'delete' ? $q->delete() : $q->update($table === 'refund_events' ? ['reason' => 'forged'] : ['amount' => '1.00']);
    });
})->with([['refunds', 'delete'], ['refunds', 'update'], ['refund_items', 'delete'], ['refund_items', 'update'], ['refund_events', 'delete'], ['refund_events', 'update'], ['refund_compensations', 'delete']]);
test('postgresql cannot complete without compensation revocation or audit', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RF::request();
    $a = RF::process($r);
    reject13(fn () => DB::table('refunds')->where('id', $r->id)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $a->id, 'external_reference' => 'FAKE-NO-EFFECTS']));
});
test('postgresql forbids revoked purchase reactivation and refunded earning release', function (string $table) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RF::request();
    RF::complete($r);
    reject13(function () use ($table) {
        $row = DB::table($table)->first();
        DB::table($table)->where('id', $row->id)->update($table === 'author_earnings' ? ['status' => 'available', 'released_at' => now()] : ['status' => 'active', 'revoked_at' => null]);
    });
})->with(['entitlements', 'licenses', 'author_earnings']);

test('administrator may initiate a refund while purchase ownership and actor identity stay authoritative', function () {
    $o = RF::order();
    $a = User::factory()->create()->assignRole('admin');
    $this->actingAs($a)->get(route('admin.refunds.create', $o))->assertOk();
    $this->post(route('admin.refunds.store', $o), [...RF::input($o), 'customer_user_id' => $a->id])->assertRedirect();
    $r = Refund::firstOrFail();
    expect($r->customer_user_id)->toBe($o->user_id)->and($r->getAttribute('requested_by'))->toBe($a->id);
});
test('completion ignores forged identity dates and economics', function () {
    $r = RF::request();
    $a = RF::process($r);
    $this->actingAs($a)->post(route('admin.refunds.complete', $r), ['external_reference' => 'MANUAL-COMPLETE-ACTOR', 'completed_by' => 999999, 'completed_at' => '2000-01-01', 'author_amount' => '0.00', 'amount' => '0.01'])->assertRedirect();
    expect($r->fresh()->getAttribute('completed_by'))->toBe($a->id)->and($r->fresh()->getAttribute('amount'))->toBe('5000.00')->and(RefundCompensation::first()->getAttribute('author_amount'))->toBe('4000.00');
});
test('all refund lifecycle events carry only safe IDs', function () {
    $events = [RefundRequested::class, RefundApproved::class, RefundProcessing::class, RefundCompleted::class, RefundFailed::class, RefundCancelled::class];
    Event::fake($events);
    RF::complete(RF::request());
    $r = RF::request();
    $a = RF::process($r);
    app(RefundWorkflow::class)->transition($a, $r, 'fail', ['reason' => 'External refund did not succeed.'], true);
    $r = RF::request();
    app(RefundWorkflow::class)->transition(User::findOrFail($r->customer_user_id), $r, 'cancel');
    foreach ($events as $event) {
        Event::assertDispatched($event, fn ($e) => array_keys(get_object_vars($e)) === ['refundId']);
    }
});
test('refund reconciliation is read only repeatable and validates references', function () {
    $r = RF::request();
    RF::complete($r);
    $before = AuthorEarningLedgerEntry::count();
    expect(app(ReconcileRefunds::class)->run())->toBe(['checked' => 1, 'errors' => 0]);
    $this->artisan('commerce:reconcile-refunds', ['--refund' => $r->getAttribute('reference')])->assertSuccessful();
    $this->artisan('commerce:reconcile-refunds', ['--refund' => 'UNKNOWN-REFUND'])->assertFailed();
    expect(AuthorEarningLedgerEntry::count())->toBe($before)->and(RefundCompensation::count())->toBe(1);
});
test('customer cannot select a real purchased item from another order', function () {
    $o = RF::order();
    $other = RF::order();
    expect(fn () => app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, RF::input($o, [$other->items->first()->id])))->toThrow(ValidationException::class);
});
test('refund report retains original author and official sales and shows exact compensated platform net', function () {
    $o = RF::mixed();
    RF::complete(RF::request($o));
    $row = app(RefundPresentation::class)->report()[0];
    expect($row['gross_refunded'])->toBe($o->grand_total)->and($row['net_sales'])->toBe('0.00')->and($row['net_commission'])->toBe('0.00')->and($row['platform_net'])->toBe('0.00')->and($row['original_commission'])->toBe('2000.00')->and($row['author_adjustment'])->toBe('8000.00');
});
