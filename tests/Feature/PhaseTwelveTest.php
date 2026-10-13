<?php

use App\Enums\PayoutStatus;
use App\Events\Payouts\PayoutApproved;
use App\Events\Payouts\PayoutCancelled;
use App\Events\Payouts\PayoutFailed;
use App\Events\Payouts\PayoutPaid;
use App\Events\Payouts\PayoutProcessing;
use App\Events\Payouts\PayoutRequested;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\AuthorPayoutMethod;
use App\Models\CommissionPolicy;
use App\Models\PayoutAllocation;
use App\Models\PayoutEvent;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutMethods;
use App\Services\Payouts\PayoutMinimums;
use App\Services\Payouts\PayoutPresentation;
use App\Services\Payouts\PayoutWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EarningFixtures;
use Tests\Support\PayoutFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, CommissionPolicySeeder::class]);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
test('author saves encrypted masked bank instructions and safe list', function () {
    $a = User::factory()->create()->assignRole('author');
    $m = PayoutFixtures::method($a);
    expect($m->getRawOriginal('details_encrypted'))->not->toContain('123456789012')->not->toContain('Business Author');
    expect($m->getAttribute('details_encrypted')['account_number'])->toBe('123456789012')->and($m->getAttribute('masked_account'))->toBe('•••• 9012');
    $this->actingAs($a)->get(route('author.payout-methods.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->has('methods.data', 1)->missing('methods.data.0.details_encrypted')->missing('methods.data.0.details_fingerprint')->missing('methods.data.0.account_number'));
});
test('customers and reviewers cannot create payout methods or payouts', function (string $role) {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $this->post(route('author.payout-methods.store'), PayoutFixtures::bank())->assertForbidden();
    $this->post(route('author.payouts.store'), [])->assertForbidden();
    $this->get(route('author.payouts.index'))->assertForbidden();
})->with(['customer', 'reviewer']);
test('method UUID ownership cannot be bypassed', function () {
    $a = User::factory()->create()->assignRole('author');
    $other = User::factory()->create()->assignRole('author');
    $m = PayoutFixtures::method($a);
    $this->actingAs($other)->get(route('author.payout-methods.edit', $m))->assertNotFound();
    $this->put(route('author.payout-methods.update', $m), PayoutFixtures::bank())->assertNotFound();
    $this->post(route('author.payout-methods.disable', $m))->assertNotFound();
});
test('country dependent bank validation supports international instructions without IFSC', function () {
    $a = User::factory()->create()->assignRole('author');
    $data = PayoutFixtures::bank('EUR');
    $data['country_code'] = 'DE';
    $data['account_number'] = null;
    $data['iban'] = 'DE89370400440532013000';
    $data['routing_code'] = null;
    $data['swift_bic'] = 'COBADEFFXXX';
    $m = app(PayoutMethods::class)->save($a, $data);
    expect($m->getAttribute('masked_account'))->toBe('•••• 3000')->and($m->getAttribute('currency'))->toBe('EUR');
});
test('invalid bank instructions fail with field messages', function (array $change) {
    $a = User::factory()->create()->assignRole('author');
    expect(fn () => app(PayoutMethods::class)->save($a, [...PayoutFixtures::bank(), ...$change]))->toThrow(ValidationException::class);
})->with([[['routing_code' => 'BAD']], [['account_number' => '']], [['country_code' => 'XX']], [['currency' => 'AUD']], [['swift_bic' => 'invalid']], [['iban' => 'wrong']]]);
test('default active method is unique per author currency and duplicate detection is keyed', function () {
    $a = User::factory()->create()->assignRole('author');
    $first = PayoutFixtures::method($a);
    $second = app(PayoutMethods::class)->save($a, PayoutFixtures::bank(number: '999999999999'));
    expect($first->fresh()->getAttribute('is_default'))->toBeFalse()->and($second->getAttribute('is_default'))->toBeTrue()->and(strlen($second->getAttribute('details_fingerprint')))->toBe(64);
    expect(fn () => PayoutFixtures::method($a))->toThrow(ValidationException::class);
    PayoutFixtures::method($a, 'USD');
    expect(AuthorPayoutMethod::where('is_default', true)->count())->toBe(2);
});
test('disabled method cannot fund payout and remains historical', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    app(PayoutMethods::class)->disable($e->author, $m);
    expect(fn () => app(PayoutWorkflow::class)->request($e->author, PayoutFixtures::input($m)))->toThrow(ValidationException::class);
    expect(fn () => $m->delete())->toThrow(LogicException::class);
});
test('destination edits do not rewrite existing payout encrypted or display snapshots', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    $p = PayoutFixtures::request($e->author, method: $m);
    $cipher = $p->getRawOriginal('method_snapshot_encrypted');
    app(PayoutMethods::class)->save($e->author, PayoutFixtures::bank(number: '999999999999'), $m);
    expect($p->fresh()->getRawOriginal('method_snapshot_encrypted'))->toBe($cipher)->and($p->fresh()->getAttribute('method_snapshot_encrypted')['account_number'])->toBe('123456789012')->and($p->fresh()->getAttribute('method_display_snapshot')['masked_account'])->toBe('•••• 9012');
});
test('pending earnings cannot fund payout', function () {
    CommissionPolicy::first()->forceFill(['hold_days' => 7])->save();
    $p = EarningFixtures::product();
    EarningFixtures::order($p);
    $m = PayoutFixtures::method($p->owner);
    expect(fn () => app(PayoutWorkflow::class)->request($p->owner, PayoutFixtures::input($m, '1000.00')))->toThrow(ValidationException::class);
    expect(PayoutRequest::count())->toBe(0);
});
test('invalid payout amounts never reserve funds', function (string $amount) {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    expect(fn () => app(PayoutWorkflow::class)->request($e->author, PayoutFixtures::input($m, $amount)))->toThrow(ValidationException::class);
    expect(PayoutRequest::count())->toBe(0)->and(PayoutAllocation::count())->toBe(0);
})->with(['0.00', '-1.00', '5000.01', '999.99', '1000.001', '1e3']);
test('currency and destination ownership are authoritative', function (string $case) {
    $e = PayoutFixtures::available();
    $a = $e->author;
    $other = User::factory()->create()->assignRole('author');
    $m = PayoutFixtures::method($case === 'other' ? $other : $a, $case === 'currency' ? 'USD' : 'INR');
    $data = PayoutFixtures::input($m);
    $data['currency'] = 'INR';
    expect(fn () => app(PayoutWorkflow::class)->request($a, $data))->toThrow(ValidationException::class);
})->with(['other', 'currency']);
test('reservation creates exact partial allocations immutable snapshots and audit atomically', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author, '2500.00');
    expect($p->status)->toBe(PayoutStatus::Requested)->and($p->allocations->first()->getAttribute('amount'))->toBe('2500.00')->and($e->fresh()->getAttribute('author_amount'))->toBe('5000.00');
    expect(app(FinancialBalances::class)->all($e->author_user_id)[0])->toMatchArray(['available' => '2500.00', 'reserved' => '2500.00', 'paid' => '0.00', 'earned' => '5000.00']);
    expect(PayoutEvent::count())->toBe(1)->and(AuthorEarningLedgerEntry::where('type', 'payout_reserved')->count())->toBe(1);
});
test('FIFO consumes older earnings and supports partial final allocation', function () {
    $a = User::factory()->create()->assignRole('author');
    $first = PayoutFixtures::available($a, gross: '2500.00');
    $second = PayoutFixtures::available($a, gross: '5000.00');
    $p = PayoutFixtures::request($a, '3000.00');
    expect($p->allocations->pluck('author_earning_id')->all())->toBe([$first->id, $second->id])->and($p->allocations->pluck('amount')->all())->toBe(['2000.00', '1000.00']);
});
test('request keys are owner scoped idempotent and reject changed terms', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    $input = PayoutFixtures::input($m);
    $s = app(PayoutWorkflow::class);
    $one = $s->request($e->author, $input);
    $two = $s->request($e->author, $input);
    expect($two->id)->toBe($one->id)->and(PayoutRequest::count())->toBe(1)->and(PayoutAllocation::count())->toBe(1);
    expect(fn () => $s->request($e->author, [...$input, 'amount' => '1000.00']))->toThrow(ValidationException::class);
});
test('author request ignores forged identity balance status and allocation fields', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    $this->actingAs($e->author)->post(route('author.payouts.store'), [...PayoutFixtures::input($m), 'author_user_id' => 999999, 'status' => 'paid', 'paid_at' => now(), 'available' => '999999.00', 'allocation_ids' => [999]])->assertRedirect();
    $p = PayoutRequest::first();
    expect($p->author_user_id)->toBe($e->author_user_id)->and($p->status)->toBe(PayoutStatus::Requested);
});
test('author payout detail is owner scoped and private', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $this->actingAs($e->author)->get(route('author.payouts.show', $p))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->missing('payout.method_snapshot_encrypted')->missing('payout.admin_note')->missing('payout.external_reference')->has('payout.allocations', 1)->has('payout.events', 1));
    $this->actingAs(User::factory()->create()->assignRole('author'))->get(route('author.payouts.show', $p))->assertNotFound();
    $this->post(route('author.payouts.cancel', $p))->assertNotFound();
});
test('queue read permission does not authorize financial mutations or bank reveal', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $reader = User::factory()->create()->givePermissionTo('payouts.view');
    $this->actingAs($reader)->get(route('admin.payouts.index'))->assertOk();
    $this->get(route('admin.payouts.show', $p))->assertOk();
    $this->post(route('admin.payouts.approve', $p))->assertForbidden();
    $this->post(route('admin.payouts.instructions', $p))->assertForbidden();
});
test('customer reviewer author cannot access administrative payout queue', function (string $role) {
    $this->actingAs(User::factory()->create()->assignRole($role))->get(route('admin.payouts.index'))->assertForbidden();
})->with(['customer', 'reviewer', 'author']);
test('approval and processing retain reservation and capture authenticated actors', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $admin = PayoutFixtures::process($p);
    $p->refresh();
    expect($p->status)->toBe(PayoutStatus::Processing)->and($p->getAttribute('reviewed_by'))->toBe($admin->id)->and($p->getAttribute('processing_by'))->toBe($admin->id)->and(app(FinancialBalances::class)->all($e->author_user_id)[0]['reserved'])->toBe('4000.00');
});
test('paid requires external reference and settles exactly once', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $admin = PayoutFixtures::process($p);
    $s = app(PayoutWorkflow::class);
    expect(fn () => $s->transition($admin, $p, 'pay', asAdmin: true))->toThrow(ValidationException::class);
    $s->transition($admin, $p, 'pay', ['external_reference' => 'BANK-ABC123', 'paid_by' => 999], true);
    expect($p->fresh()->status)->toBe(PayoutStatus::Paid)->and($p->fresh()->getAttribute('paid_by'))->toBe($admin->id)->and(app(FinancialBalances::class)->all($e->author_user_id)[0])->toMatchArray(['available' => '1000.00', 'reserved' => '0.00', 'paid' => '4000.00', 'earned' => '5000.00']);
    expect(fn () => $s->transition($admin, $p, 'pay', ['external_reference' => 'BANK-DUPLICATE'], true))->toThrow(ValidationException::class);
    expect(AuthorEarningLedgerEntry::where('type', 'payout_paid')->count())->toBe(1);
});
test('failed payout requires reason releases funds and retains audit', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $admin = PayoutFixtures::process($p);
    $s = app(PayoutWorkflow::class);
    expect(fn () => $s->transition($admin, $p, 'fail', asAdmin: true))->toThrow(ValidationException::class);
    $s->transition($admin, $p, 'fail', ['reason' => 'Bank rejected transfer'], true);
    expect($p->fresh()->status)->toBe(PayoutStatus::Failed)->and(app(FinancialBalances::class)->all($e->author_user_id)[0])->toMatchArray(['available' => '5000.00', 'reserved' => '0.00', 'paid' => '0.00']);
    expect(PayoutEvent::count())->toBe(4)->and(PayoutAllocation::count())->toBe(1);
});
test('author cancellation returns requested funds and permits reallocation', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    $p = PayoutFixtures::request($e->author, method: $m);
    app(PayoutWorkflow::class)->transition($e->author, $p, 'cancel');
    expect(app(FinancialBalances::class)->all($e->author_user_id)[0])->toMatchArray(['available' => '5000.00', 'reserved' => '0.00']);
    $next = PayoutFixtures::request($e->author, method: $m);
    expect($next->id)->not->toBe($p->id)->and($next->payout_number)->not->toBe($p->payout_number);
});
test('author cannot cancel approved processing or paid payout', function (string $status) {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = User::factory()->create()->assignRole('admin');
    $s = app(PayoutWorkflow::class);
    $s->transition($a, $p, 'approve', asAdmin: true);
    if ($status !== 'approved') {
        $s->transition($a, $p, 'process', asAdmin: true);
    }if ($status === 'paid') {
        $s->transition($a, $p, 'pay', ['external_reference' => 'BANK-PAID'], true);
    }expect(fn () => $s->transition($e->author, $p, 'cancel'))->toThrow(ValidationException::class);
})->with(['approved', 'processing', 'paid']);
test('admin cancellation requires reason and applies only requested or approved', function (string $state) {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = User::factory()->create()->assignRole('admin');
    $s = app(PayoutWorkflow::class);
    if ($state === 'approved') {
        $s->transition($a, $p, 'approve', asAdmin: true);
    }expect(fn () => $s->transition($a, $p, 'cancel', asAdmin: true))->toThrow(ValidationException::class);
    $s->transition($a, $p, 'cancel', ['reason' => 'Author requested cancellation'], true);
    expect(app(FinancialBalances::class)->all($e->author_user_id)[0]['available'])->toBe('5000.00');
})->with(['requested', 'approved']);
test('paid failed and cancelled payouts remain terminal', function (string $action) {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = PayoutFixtures::process($p);
    $s = app(PayoutWorkflow::class);
    $s->transition($a, $p, 'pay', ['external_reference' => 'BANK-PAID'], true);
    expect(fn () => $s->transition($a, $p, $action, ['reason' => 'Do not change terminal state', 'external_reference' => 'BANK-OTHER'], true))->toThrow(ValidationException::class);
    expect(fn () => $p->refresh()->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class);
})->with(['cancel', 'fail', 'pay', 'approve']);
test('financial allocations events and original earning facts are immutable', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    expect(fn () => $p->allocations->first()->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class);
    expect(fn () => $p->allocations->first()->delete())->toThrow(LogicException::class);
    expect(fn () => $p->events->first()->delete())->toThrow(LogicException::class);
    expect(fn () => $p->events->first()->forceFill(['status' => 'paid'])->save())->toThrow(LogicException::class);
    expect(fn () => $p->forceFill(['method_snapshot_encrypted' => ['account_number' => 'FORGED']])->save())->toThrow(LogicException::class);
    expect(fn () => $e->forceFill(['author_amount' => '1.00'])->save())->toThrow(LogicException::class);
});
test('multi currency balances never fund a different currency payout', function () {
    $a = User::factory()->create()->assignRole('author');
    PayoutFixtures::available($a);
    PayoutFixtures::available($a, 'USD', '100.00');
    $inr = PayoutFixtures::request($a);
    $usd = PayoutFixtures::method($a, 'USD');
    $p = app(PayoutWorkflow::class)->request($a, PayoutFixtures::input($usd, '40.00'));
    $b = app(FinancialBalances::class)->all($a->id);
    expect($b[0])->toMatchArray(['currency' => 'INR', 'available' => '1000.00', 'reserved' => '4000.00'])->and($b[1])->toMatchArray(['currency' => 'USD', 'available' => '40.00', 'reserved' => '40.00']);
});
test('production requires explicit minimum policy and does not silently use development rules', function () {
    app()->instance('env', 'production');
    config()->set('payouts.minimums.INR', null);
    expect(fn () => app(PayoutMinimums::class)->forCurrency('INR'))->toThrow(ValidationException::class);
    config()->set('payouts.minimums.INR', '2000.00');
    expect(app(PayoutMinimums::class)->forCurrency('INR'))->toBe('2000.00');
    app()->instance('env', 'testing');
});
test('author payout method and administrator audit prevent account deletion', function () {
    $a = User::factory()->create()->assignRole('author');
    PayoutFixtures::method($a);
    $this->actingAs($a)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(User::whereKey($a->id)->exists())->toBeTrue();
});
test('explicit admin instructions reveal uses old snapshot and requires approved state', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = User::factory()->create()->assignRole('admin');
    $this->actingAs($a)->postJson(route('admin.payouts.instructions', $p))->assertStatus(422);
    app(PayoutWorkflow::class)->transition($a, $p, 'approve', asAdmin: true);
    $this->postJson(route('admin.payouts.instructions', $p))->assertOk()->assertJsonPath('instructions.account_number', '123456789012')->assertHeader('Cache-Control', 'no-store, private');
});
test('invalid sensitive input is not flashed into session', function () {
    $a = User::factory()->create()->assignRole('author');
    $this->actingAs($a)->post(route('author.payout-methods.store'), [...PayoutFixtures::bank(), 'routing_code' => 'BAD'])->assertSessionHasErrors('routing_code');
    expect(session()->getOldInput('account_number'))->toBeNull()->and(session()->getOldInput('account_holder_name'))->toBeNull();
});
test('payout filters reporting and author dashboard use consistent ledger balances', function () {
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $this->actingAs($e->author)->get(route('author.payouts.index', ['status' => 'requested', 'currency' => 'INR']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('payouts.data', 1)->where('balances.0.reserved', '4000.00'));
    $this->get(route('author.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('earnings.0.available', '1000.00')->where('earnings.0.reserved', '4000.00'));
    expect(app(PayoutPresentation::class)->report()[0])->toMatchArray(['currency' => 'INR', 'status' => 'requested', 'amount' => '4000.00']);
});
function phase12Reject(callable $write): void
{
    expect(fn () => DB::transaction(function () use ($write) {
        $write();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
}
test('postgresql prevents financial payout mutation or deletion', function (string $field, mixed $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    phase12Reject(fn () => DB::table('payout_requests')->where('id', $p->id)->update([$field => $value]));
})->with([['amount', '-1.00'], ['currency', 'USD'], ['author_user_id', 999999], ['method_snapshot_encrypted', 'forged'], ['requested_at', '2000-01-01'], ['status', 'paid']]);
test('postgresql allocation audit and payout ledger are append only', function (string $table, string $operation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    phase12Reject(function () use ($table, $operation) {
        $row = DB::table($table)->orderByDesc('id')->first();
        $q = DB::table($table)->where('id', $row->id);
        $operation === 'delete' ? $q->delete() : $q->update($table === 'payout_events' ? ['reason' => 'Forged history'] : ['amount' => '1.00']);
    });
})->with([['payout_allocations', 'update'], ['payout_allocations', 'delete'], ['payout_events', 'update'], ['payout_events', 'delete'], ['author_earning_ledger_entries', 'update'], ['author_earning_ledger_entries', 'delete']]);
test('postgresql rejects unsupported status updates missing audit and settlement movements', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = PayoutFixtures::process($p);
    phase12Reject(fn () => DB::table('payout_requests')->where('id', $p->id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $a->id, 'external_reference' => 'BANK-MISSING-AUDIT']));
});
test('postgresql default uniqueness currency and historical method retention are enforced', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $m = AuthorPayoutMethod::first();
    phase12Reject(function () use ($m, $case) {
        if ($case === 'delete') {
            DB::table('author_payout_methods')->where('id', $m->id)->delete();
        } elseif ($case === 'currency') {
            DB::table('author_payout_methods')->where('id', $m->id)->update(['currency' => 'USD']);
        } else {
            $data = $m->getAttributes();
            unset($data['id']);
            $data['public_uuid'] = (string) Str::uuid();
            $data['details_fingerprint'] = str_repeat('a', 64);
            DB::table('author_payout_methods')->insert($data);
        }
    });
})->with(['delete', 'currency', 'default']);

test('malformed bank routing array returns validation errors instead of a server error', function () {
    $a = User::factory()->create()->assignRole('author');
    $this->actingAs($a)->post(route('author.payout-methods.store'), [...PayoutFixtures::bank(), 'routing_code' => ['forged']])->assertSessionHasErrors('routing_code');
    expect(AuthorPayoutMethod::count())->toBe(0);
});
test('postgresql enforces allocation capacity ownership and currency', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author, '4000.00');
    $other = PayoutFixtures::available($case === 'currency' ? $e->author : null, $case === 'currency' ? 'USD' : 'INR');
    phase12Reject(fn () => DB::table('payout_allocations')->insert(['payout_request_id' => $p->id, 'author_earning_id' => $case === 'capacity' ? $e->id : $other->id, 'amount' => $case === 'capacity' ? '2000.00' : '1.00', 'created_at' => now()]));
})->with(['capacity', 'ownership', 'currency']);
test('postgresql prevents forged early processing fields and backdated approval', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $a = User::factory()->create()->assignRole('admin');
    phase12Reject(fn () => DB::table('payout_requests')->where('id', $p->id)->update(['status' => 'approved', 'reviewed_at' => $case === 'backdated' ? now()->subDay() : now(), 'reviewed_by' => $a->id, ...($case === 'processing' ? ['processing_at' => now(), 'processing_by' => $a->id] : [])]));
})->with(['processing', 'backdated']);

test('workflow rollback emits no payout event or reservation and success emits safe domain events', function () {
    $e = PayoutFixtures::available();
    $m = PayoutFixtures::method($e->author);
    $events = [PayoutRequested::class, PayoutApproved::class, PayoutProcessing::class, PayoutPaid::class, PayoutFailed::class, PayoutCancelled::class];
    Event::fake($events);
    expect(fn () => DB::transaction(function () use ($e, $m) {
        PayoutFixtures::request($e->author, '4000.00', $m);
        throw new RuntimeException('Rollback verification');
    }))->toThrow(RuntimeException::class);
    Event::assertNotDispatched(PayoutRequested::class);
    expect(PayoutRequest::count())->toBe(0)->and(app(FinancialBalances::class)->available($e->author_user_id, 'INR'))->toBe('5000.00');
    $p = PayoutFixtures::request($e->author, '4000.00', $m);
    $a = PayoutFixtures::process($p);
    $s = app(PayoutWorkflow::class);
    $s->transition($a, $p, 'pay', ['external_reference' => 'BANK-EVENTS'], true);
    $f = PayoutFixtures::request($e->author, '1000.00', $m);
    PayoutFixtures::process($f, $a);
    $s->transition($a, $f, 'fail', ['reason' => 'Transfer failed externally'], true);
    $c = PayoutFixtures::request($e->author, '1000.00', $m);
    $s->transition($e->author, $c, 'cancel');
    foreach ($events as $event) {
        Event::assertDispatched($event, fn ($v) => array_keys(get_object_vars($v)) === ['payoutId']);
    }
    expect(fn () => $s->transition($a, $f, 'pay', ['external_reference' => 'NO-RETRY'], true))->toThrow(ValidationException::class);
    expect(fn () => $s->transition($a, $c, 'approve', asAdmin: true))->toThrow(ValidationException::class);
});

test('postgresql requires complete reservation allocations and initial audit at commit', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author, '1000.00');
    phase12Reject(function () use ($e, $p, $case) {
        $data = $p->getAttributes();
        unset($data['id']);
        $data['public_uuid'] = (string) Str::uuid();
        $data['request_key'] = (string) Str::uuid();
        $data['payout_number'] = 'TMP-PAY-'.Str::ulid();
        $id = DB::table('payout_requests')->insertGetId($data);
        if ($case === 'no-reservation') {
            DB::table('payout_allocations')->insert(['payout_request_id' => $id, 'author_earning_id' => $e->id, 'amount' => '1000.00', 'created_at' => $data['requested_at']]);
            DB::table('payout_events')->insert(['payout_request_id' => $id, 'actor_id' => $e->author_user_id, 'status' => 'requested', 'occurred_at' => $data['requested_at']]);
        }
    });
})->with(['no-allocations', 'no-reservation']);

test('postgresql cannot skip an intermediate audit even inside one transaction', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    $admin = User::factory()->create()->assignRole('admin');
    phase12Reject(function () use ($p, $admin) {
        $time = now();
        DB::table('payout_requests')->where('id', $p->id)->update(['status' => 'approved', 'reviewed_at' => $time, 'reviewed_by' => $admin->id]);
        DB::table('payout_requests')->where('id', $p->id)->update(['status' => 'processing', 'processing_at' => $time, 'processing_by' => $admin->id]);
        DB::table('payout_events')->insert(['payout_request_id' => $p->id, 'actor_id' => $admin->id, 'previous_status' => 'requested', 'status' => 'processing', 'occurred_at' => $time]);
    });
    expect($p->fresh()->status)->toBe(PayoutStatus::Requested);
});
