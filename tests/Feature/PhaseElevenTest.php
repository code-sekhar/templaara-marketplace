<?php

use App\Enums\AuthorEarningStatus;
use App\Events\Commerce\OrderPaid;
use App\Listeners\ProvisionAuthorEarnings;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\Cart;
use App\Models\CommissionPolicy;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\Money;
use App\Services\Commerce\PaymentLifecycle;
use App\Services\Earnings\CommissionMath;
use App\Services\Earnings\CommissionPolicies;
use App\Services\Earnings\EarningPresentation;
use App\Services\Earnings\EarningProvisioner;
use App\Services\Earnings\ReconcileEarnings;
use App\Services\Earnings\ReleaseEarnings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, CommissionPolicySeeder::class]);
    CommissionPolicy::first()->forceFill(['effective_from' => now()->subMonth()])->save();
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
test('commission money is exact with half up rounding', function (string $gross, string $rate, string $commission, string $net) {
    expect(app(CommissionMath::class)->split($gross, $rate))->toBe(['commission_amount' => $commission, 'author_amount' => $net]);
})->with([['5000.00', '20.00', '1000.00', '4000.00'], ['0.03', '50.00', '0.02', '0.01'], ['0.00', '20.00', '0.00', '0.00'], ['99.99', '0.00', '0.00', '99.99'], ['99.99', '100.00', '99.99', '0.00'], ['999999999999.99', '12.34', '123400000000.00', '876599999999.99']]);
test('invalid economics fail before any calculation', function (string $gross, string $rate) {
    expect(fn () => app(CommissionMath::class)->split($gross, $rate))->toThrow(Exception::class);
})->with([['-0.01', '20'], ['1.001', '20'], ['10', '101'], ['10', '-1'], ['10', '20.001']]);
test('policy seeding is idempotent and never replaces admin configuration', function () {
    CommissionPolicy::first()->forceFill(['percentage' => '15.00'])->save();
    $this->seed(CommissionPolicySeeder::class);
    expect(CommissionPolicy::count())->toBe(1)->and(CommissionPolicy::first()->getAttribute('percentage'))->toBe('15.00');
});
test('author checkout stores immutable seller and economics before payment', function () {
    $p = EarningFixtures::product();
    $o = EarningFixtures::order($p, false);
    $i = $o->items->first();
    expect($i->getAttribute('seller_type'))->toBe('author')->and($i->getAttribute('author_user_id'))->toBe($p->owner_id)->and($i->getAttribute('commission_rate'))->toBe('20.00')->and($i->getAttribute('commission_amount'))->toBe('1000.00')->and($i->getAttribute('author_amount'))->toBe('4000.00')->and($i->getAttribute('earning_hold_days'))->toBe(7)->and(AuthorEarning::count())->toBe(0);
});
test('verified paid sale creates one pending earning and ledger entry', function () {
    $o = EarningFixtures::order();
    $e = AuthorEarning::firstOrFail();
    expect($e->order_id)->toBe($o->id)->and($e->status)->toBe(AuthorEarningStatus::Pending)->and($e->getAttribute('gross_amount'))->toBe('5000.00')->and($e->getAttribute('author_amount'))->toBe('4000.00')->and($e->getAttribute('available_at')->equalTo($o->getAttribute('paid_at')->addDays(7)))->toBeTrue()->and(AuthorEarningLedgerEntry::count())->toBe(1);
});
test('official sale has platform seller and no artificial author earning', function () {
    $o = EntitlementFixtures::order();
    $i = $o->items->first();
    expect($i->getAttribute('seller_type'))->toBe('platform')->and($i->getAttribute('commissionable_amount'))->toBe($i->line_total)->and($i->getAttribute('commission_rate'))->toBeNull()->and(AuthorEarning::count())->toBe(0);
});
test('unpaid failed and cancelled orders cannot generate earnings', function (string $outcome) {
    $o = EarningFixtures::order(paid: false);
    if ($outcome === 'failed') {
        EntitlementFixtures::pay($o, 'failed');
    }if ($outcome === 'cancelled') {
        app(PaymentLifecycle::class)->cancel($o->user, $o);
    }expect(fn () => app(EarningProvisioner::class)->provision($o))->toThrow(ValidationException::class);
    expect(AuthorEarning::count())->toBe(0);
})->with(['pending', 'failed', 'cancelled']);
test('duplicate callbacks listener and reconciliation cannot duplicate financial rows', function () {
    $o = EarningFixtures::order();
    app(ProvisionAuthorEarnings::class)->handle(new OrderPaid($o));
    app(EarningProvisioner::class)->provision($o);
    app(ReconcileEarnings::class)->run();
    EntitlementFixtures::pay($o);
    expect(AuthorEarning::count())->toBe(1)->and(AuthorEarningLedgerEntry::count())->toBe(1);
});
test('reconciliation recovers missed events using original checkout terms', function () {
    Event::fake([OrderPaid::class]);
    $p = EarningFixtures::product();
    $o = EarningFixtures::order($p);
    $policy = CommissionPolicy::first();
    $policy->forceFill(['is_active' => false])->save();
    $p->forceFill(['owner_id' => User::factory()->create()->assignRole('author')->id])->save();
    expect(AuthorEarning::count())->toBe(0);
    $this->artisan('commerce:reconcile-author-earnings', ['--dry-run' => true])->assertSuccessful();
    expect(AuthorEarning::count())->toBe(0);
    $this->artisan('commerce:reconcile-author-earnings', ['--order' => $o->order_number])->assertSuccessful();
    $e = AuthorEarning::first();
    expect($e->author_user_id)->toBe($o->items->first()->getAttribute('author_user_id'))->and($e->getAttribute('commission_rate'))->toBe('20.00');
    $this->artisan('commerce:reconcile-author-earnings')->assertSuccessful();
    expect(AuthorEarning::count())->toBe(1);
});
test('provisioning failure preserves paid truth and can be recovered', function () {
    $this->mock(EarningProvisioner::class)->shouldReceive('provision')->andThrow(new RuntimeException('simulated failure'));
    Log::spy();
    $o = EarningFixtures::order();
    expect($o->payment_status->value)->toBe('paid')->and(AuthorEarning::count())->toBe(0);
    $this->app->forgetInstance(EarningProvisioner::class);
    expect(app(ReconcileEarnings::class)->run()['earnings'])->toBe(1);
});
test('pending earnings cannot release early and mature once', function () {
    $this->travel(-8)->days();
    EarningFixtures::order();
    $e = AuthorEarning::first();
    $s = app(ReleaseEarnings::class);
    expect($s->release($e))->toBeFalse();
    $this->travelBack();
    $this->artisan('commerce:release-author-earnings')->assertSuccessful();
    expect($e->fresh()->status)->toBe(AuthorEarningStatus::Available)->and(AuthorEarningLedgerEntry::count())->toBe(2);
    $this->artisan('commerce:release-author-earnings')->assertSuccessful();
    expect(AuthorEarningLedgerEntry::count())->toBe(2);
    $this->travelBack();
});
test('zero day hold still provisions pending before explicit availability processing', function () {
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    EarningFixtures::order();
    $e = AuthorEarning::first();
    expect($e->status)->toBe(AuthorEarningStatus::Pending);
    app(ReleaseEarnings::class)->release($e);
    expect($e->fresh()->status)->toBe(AuthorEarningStatus::Available)->and(AuthorEarningLedgerEntry::count())->toBe(2);
});
test('ledger balances never merge currencies and correctly move pending to available', function () {
    $a = User::factory()->create()->assignRole('author');
    $this->travel(-8)->days();
    EarningFixtures::order(EarningFixtures::product($a, '5000.00', 'INR'));
    EarningFixtures::order(EarningFixtures::product($a, '100.00', 'USD'));
    $rows = app(EarningPresentation::class)->balances($a->id);
    expect($rows)->toHaveCount(2)->and($rows[0])->toMatchArray(['currency' => 'INR', 'earned' => '4000.00', 'pending' => '4000.00', 'available' => '0.00'])->and($rows[1]['pending'])->toBe('80.00');
    $this->travelBack();
    app(ReleaseEarnings::class)->run();
    expect(app(EarningPresentation::class)->balances($a->id)[0])->toMatchArray(['pending' => '0.00', 'available' => '4000.00']);
    $this->travelBack();
});
test('author sees only owned safe earnings and detail refuses another author', function () {
    EarningFixtures::order();
    $e = AuthorEarning::first();
    $a = $e->author;
    $other = User::factory()->create()->assignRole('author');
    $this->actingAs($a)->get(route('author.earnings.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('earnings/Index')->has('earnings.data', 1)->where('earnings.data.0.net', '4000.00')->missing('earnings.data.0.email')->missing('earnings.data.0.billing_email'));
    $this->get(route('author.earnings.show', $e))->assertOk()->assertInertia(fn (Assert $p) => $p->has('ledger', 1));
    $this->actingAs($other)->get(route('author.earnings.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('earnings.data', 0)->has('balances', 0));
    $this->get(route('author.earnings.show', $e))->assertNotFound();
});
test('customer reviewer and guest cannot use author earnings', function (string $role) {
    if ($role !== 'guest') {
        $this->actingAs(User::factory()->create()->assignRole($role));
    }$r = $this->get(route('author.earnings.index'));
    $role === 'guest' ? $r->assertRedirect(route('login')) : $r->assertForbidden();
})->with(['guest', 'customer', 'reviewer']);
test('finance reports use permissions and preserve currency boundaries without customer identity', function () {
    EarningFixtures::order();
    $u = User::factory()->create()->givePermissionTo('commissions.view');
    $this->actingAs($u)->get(route('admin.earnings.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('balances', 1)->has('earnings.data', 1)->has('earnings.data.0.author')->missing('earnings.data.0.customer')->missing('earnings.data.0.billing_email'));
    $this->get(route('admin.commissions.index'))->assertOk();
    $this->post(route('admin.commissions.store'), [])->assertForbidden();
});
test('customer reviewer and author cannot administer commissions or finance reports', function (string $role) {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $this->get(route('admin.commissions.index'))->assertForbidden();
    $this->get(route('admin.earnings.index'))->assertForbidden();
    $this->post(route('admin.commissions.store'), [])->assertForbidden();
})->with(['customer', 'reviewer', 'author']);
test('admin schedules new version without changing historical economics', function () {
    EarningFixtures::order();
    $old = CommissionPolicy::first();
    $a = User::factory()->create()->assignRole('admin');
    $new = app(CommissionPolicies::class)->create($a, ['name' => 'Future policy', 'percentage' => '15.00', 'hold_days' => 2, 'effective_from' => now()->addDay()->toIso8601String()]);
    expect($old->fresh()->getAttribute('percentage'))->toBe('20.00')->and($new->getAttribute('percentage'))->toBe('15.00')->and(AuthorEarning::first()->getAttribute('commission_rate'))->toBe('20.00');
    $this->travel(2)->days();
    expect(app(CommissionPolicies::class)->effective(now())->id)->toBe($new->id);
    $this->travelBack();
});
test('used policy economics cannot be edited through model', function () {
    EarningFixtures::order();
    expect(fn () => CommissionPolicy::first()->forceFill(['percentage' => '5.00'])->save())->toThrow(LogicException::class);
});
test('no effective policy fails author checkout atomically but official checkout remains available', function () {
    CommissionPolicy::first()->forceFill(['is_active' => false])->save();
    expect(fn () => EarningFixtures::order(paid: false))->toThrow(ValidationException::class);
    expect(Order::count())->toBe(0);
    EntitlementFixtures::order();
    expect(Order::count())->toBe(1);
});
test('policy validation rejects invalid rates hold and past schedules', function (array $changes) {
    $a = User::factory()->create()->assignRole('admin');
    expect(fn () => app(CommissionPolicies::class)->create($a, [...['name' => 'Policy', 'percentage' => '20.00', 'hold_days' => 7, 'effective_from' => now()->addDay()->toIso8601String()], ...$changes]))->toThrow(ValidationException::class);
})->with([[['percentage' => '-1']], [['percentage' => '100.01']], [['percentage' => '20.001']], [['percentage' => '2e1']], [['hold_days' => -1]], [['hold_days' => 3651]], [['effective_from' => '2000-01-01']]]);
test('financial models are guarded and reject updates or deletions', function () {
    EarningFixtures::order();
    $e = AuthorEarning::first();
    expect(fn () => new AuthorEarning(['author_amount' => '1.00']))->toThrow(MassAssignmentException::class);
    expect(fn () => $e->forceFill(['author_amount' => '1.00'])->save())->toThrow(LogicException::class);
    expect(fn () => $e->delete())->toThrow(LogicException::class);
    expect(fn () => AuthorEarningLedgerEntry::first()->delete())->toThrow(LogicException::class);
    expect(fn () => AuthorEarningLedgerEntry::first()->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class);
});
test('author dashboard integrates currency balances', function () {
    EarningFixtures::order();
    $a = AuthorEarning::first()->author;
    $this->actingAs($a)->get(route('author.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('earnings', 1)->where('earnings.0.pending', '4000.00'));
});
test('unknown reconciliation order reports failure', function () {
    $this->artisan('commerce:reconcile-author-earnings', ['--order' => 'unknown'])->assertFailed();
});

test('multi author and platform order accounts each exact item independently', function () {
    $p = EarningFixtures::product(price: '4000.00');
    $q = EarningFixtures::product(price: '6000.00');
    $official = CommerceFixtures::product();
    $buyer = User::factory()->create()->assignRole('customer');
    foreach ([$p, $q, $official] as $product) {
        app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    }$cart = Cart::where('user_id', $buyer->id)->first();
    $o = app(Checkout::class)->place($buyer, $cart->checkout_key, CommerceFixtures::billing());
    EntitlementFixtures::pay($o);
    expect(AuthorEarning::count())->toBe(2)->and(AuthorEarning::where('author_user_id', $p->owner_id)->first()->getAttribute('author_amount'))->toBe('3200.00')->and(AuthorEarning::where('author_user_id', $q->owner_id)->first()->getAttribute('author_amount'))->toBe('4800.00')->and(Entitlement::count())->toBe(3);
});
test('new checkouts use new policy while delayed payment keeps old commission and hold', function () {
    $p = EarningFixtures::product();
    $old = EarningFixtures::order($p, false);
    $a = User::factory()->create()->assignRole('admin');
    app(CommissionPolicies::class)->create($a, ['name' => 'New terms', 'percentage' => '15.00', 'hold_days' => 14, 'effective_from' => now()->addDay()->toIso8601String()]);
    $this->travel(2)->days();
    EntitlementFixtures::pay($old);
    $new = EarningFixtures::order($p);
    expect($old->items->first()->getAttribute('commission_rate'))->toBe('20.00')->and($new->items->first()->getAttribute('commission_rate'))->toBe('15.00')->and(AuthorEarning::where('order_id', $old->id)->first()->getAttribute('hold_days'))->toBe(7)->and(AuthorEarning::where('order_id', $new->id)->first()->getAttribute('hold_days'))->toBe(14);
    $this->travelBack();
});
test('clients have no earning mutation endpoints and cannot forge author or amount', function () {
    EarningFixtures::order();
    $e = AuthorEarning::first();
    $this->actingAs($e->author)->post(route('author.earnings.index'), ['author_user_id' => 999, 'author_amount' => '9999.00', 'status' => 'available'])->assertStatus(405);
    expect($e->fresh()->getAttribute('author_amount'))->toBe('4000.00')->and($e->fresh()->status)->toBe(AuthorEarningStatus::Pending);
});
test('policy creator account retention returns explicit validation before deleting', function () {
    $a = User::factory()->create()->assignRole('admin');
    app(CommissionPolicies::class)->create($a, ['name' => 'Future terms', 'percentage' => '15.00', 'hold_days' => 14, 'effective_from' => now()->addDay()->toIso8601String()]);
    $this->actingAs($a)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(User::whereKey($a->id)->exists())->toBeTrue();
});
test('earnings pagination retains filters and report totals are exact', function () {
    EarningFixtures::order();
    $e = AuthorEarning::first();
    $this->actingAs($e->author)->get(route('author.earnings.index', ['currency' => 'INR', 'status' => 'pending', 'search' => 'Nexora']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('earnings.data', 1)->where('filters.currency', 'INR'));
    expect(app(EarningPresentation::class)->report()[0])->toMatchArray(['gross' => '5000.00', 'commission' => '1000.00', 'earned' => '4000.00']);
});
function phase11Reject(callable $write): void
{
    expect(fn () => DB::transaction(function () use ($write) {
        $write();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
}
test('postgresql protects earning snapshot arithmetic identity and lifecycle', function (string $field, mixed $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }EarningFixtures::order();
    $e = AuthorEarning::first();
    phase11Reject(fn () => DB::table('author_earnings')->where('id', $e->id)->update([$field => $value]));
})->with([['author_amount', '3999.00'], ['gross_amount', '5001.00'], ['commission_rate', '101.00'], ['currency', 'USD'], ['author_user_id', 999999], ['available_at', '2000-01-01'], ['status', 'reversed'], ['released_at', '2000-01-01']]);
test('postgresql ledger cannot be edited deleted duplicated or attributed to another seller', function (string $operation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }EarningFixtures::order();
    $l = AuthorEarningLedgerEntry::first();
    phase11Reject(function () use ($l, $operation) {
        $q = DB::table('author_earning_ledger_entries')->where('id', $l->id);
        if ($operation === 'delete') {
            $q->delete();
        } elseif ($operation === 'update') {
            $q->update(['amount' => '1.00']);
        } else {
            $data = $l->getAttributes();
            unset($data['id']);
            if ($operation === 'forged') {
                $data['author_user_id'] = User::factory()->create()->id;
            }DB::table('author_earning_ledger_entries')->insert($data);
        }
    });
})->with(['delete', 'update', 'duplicate', 'forged']);
test('postgresql refuses missing mandatory availability ledger and early release', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }if ($case === 'missing') {
        CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    }EarningFixtures::order();
    $e = AuthorEarning::first();
    phase11Reject(fn () => DB::table('author_earnings')->where('id', $e->id)->update(['status' => 'available', 'released_at' => now()]));
})->with(['early', 'missing']);
test('postgresql refuses overlapping default policies', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }phase11Reject(fn () => DB::table('commission_policies')->insert(['name' => 'Conflict', 'percentage' => '10.00', 'hold_days' => 7, 'is_default' => true, 'is_active' => true, 'effective_from' => now()]));
});
test('postgresql protects used policy economics seller snapshots and financial retention', function (string $target) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }EarningFixtures::order();
    $e = AuthorEarning::first();
    phase11Reject(function () use ($e, $target) {
        if ($target === 'policy') {
            DB::table('commission_policies')->where('id', $e->getAttribute('commission_policy_id'))->update(['percentage' => '15.00']);
        } elseif ($target === 'seller') {
            DB::table('order_items')->where('id', $e->getAttribute('order_item_id'))->update(['author_user_id' => User::factory()->create()->id]);
        } elseif ($target === 'earning') {
            DB::table('author_earnings')->where('id', $e->id)->delete();
        } else {
            DB::table('users')->where('id', $e->author_user_id)->delete();
        }
    });
})->with(['policy', 'seller', 'earning', 'author']);

test('financial pages use private no-store responses and safe explicit projections', function () {
    EarningFixtures::order();
    $e = AuthorEarning::first();
    $this->actingAs($e->author)->get(route('author.earnings.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('author.earnings.show', $e))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $data = app(EarningPresentation::class)->row($e->load(['item', 'order']));
    expect(array_keys($data))->toBe(['uuid', 'product', 'offer', 'version', 'order_number', 'currency', 'gross', 'rate', 'commission', 'net', 'status', 'hold_days', 'available_at', 'released_at', 'created_at']);
});
test('zero and full commission rates and free author sales preserve exact ledger economics', function (string $price, string $rate, string $net) {
    CommissionPolicy::first()->forceFill(['percentage' => $rate])->save();
    EarningFixtures::order(EarningFixtures::product(price: $price));
    $e = AuthorEarning::first();
    expect($e->getAttribute('author_amount'))->toBe($net)->and($e->ledger->first()->getAttribute('amount'))->toBe($net)->and(app(Money::class)->sum([$e->getAttribute('commission_amount'), $e->getAttribute('author_amount')]))->toBe($price);
})->with([['5000.00', '0.00', '5000.00'], ['5000.00', '100.00', '0.00'], ['0.00', '20.00', '0.00']]);
