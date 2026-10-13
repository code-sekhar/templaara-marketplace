<?php

use App\Enums\ProductMediaType;
use App\Enums\ProductOrigin;
use App\Events\Settings\MarketplaceSettingChanged;
use App\Models\Cart;
use App\Models\CommissionPolicy;
use App\Models\MarketplaceSetting;
use App\Models\MarketplaceSettingEvent;
use App\Models\ProductArtifact;
use App\Models\User;
use App\Notifications\MarketplaceNotification;
use App\Services\Analytics\MarketplaceAnalytics;
use App\Services\Analytics\ReportFilters;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Payouts\PayoutMinimums;
use App\Services\Products\Files\UploadValidation;
use App\Services\Products\ProductUpdates;
use App\Services\Products\SaveProduct;
use App\Services\Settings\MarketplaceSettings as Settings;
use App\Services\Settings\UpdateMarketplaceSettings as Update;
use App\Services\Support\SupportAttachments;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures as EF;
use Tests\Support\PayoutFixtures as PF;
use Tests\Support\ProductFileFixtures as FF;
use Tests\Support\RefundFixtures as RF;
use Tests\Support\SupportFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, CommissionPolicySeeder::class]);
    FF::fakeStorage();
    config(['commerce.gateway' => 'development', 'commerce.development_enabled' => true, 'mail.default' => 'array', 'refunds.window_days' => '7']);
});
function admin18(): User
{
    return User::factory()->create()->assignRole('admin');
}
function input18(string $group, array $values): array
{
    $s = app(Settings::class)->section($group);

    return ['values' => $values, 'versions' => array_map(fn ($key) => $s[$key]['version'], array_combine(array_keys($values), array_keys($values)))];
}
function save18(string $group, array $values, ?User $actor = null): void
{
    app(Update::class)->save($actor ?? admin18(), $group, input18($group, $values));
}

test('guest must use normal authentication for marketplace settings', fn () => $this->get('/admin/settings')->assertRedirect('/login'));
test('unprivileged roles cannot inspect or mutate settings', function (string $role) {
    $this->actingAs(User::factory()->create()->assignRole($role))->get('/admin/settings')->assertForbidden();
    $this->put('/admin/settings/general', input18('general', ['marketplace_name' => 'Changed']))->assertForbidden();
})->with(['customer', 'author', 'reviewer']);
test('view permission alone exposes registry defaults and read-only authority', function () {
    $u = User::factory()->create()->givePermissionTo('settings.view');
    $this->actingAs($u)->get('/admin/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('marketplace-settings/Index')->where('sections.commerce.maximum_cart_items.value', 50)->where('sections.commerce.maximum_cart_items.version', 0));
    $this->put('/admin/settings/commerce', input18('commerce', ['maximum_cart_items' => 4]))->assertForbidden();
});
test('manage permission works without admin role', function () {
    $u = User::factory()->create()->givePermissionTo('settings.manage');
    save18('commerce', ['maximum_cart_items' => 4], $u);
    expect(app(Settings::class)->cartMaximum())->toBe(4);
});
test('admin role does not bypass revoked permission', function () {
    $u = admin18();
    $u->roles->first()->revokePermissionTo('settings.manage');
    $this->actingAs($u)->put('/admin/settings/general', input18('general', ['marketplace_name' => 'Changed']))->assertForbidden();
});
test('unknown and secret keys are never persisted', function (string $key) {
    $a = admin18();
    expect(fn () => app(Update::class)->save($a, 'general', ['values' => [$key => 'FORBIDDEN_SECRET'], 'versions' => [$key => 0]]))->toThrow(ValidationException::class);
    expect(MarketplaceSetting::count())->toBe(0)->and(MarketplaceSettingEvent::count())->toBe(0);
})->with(['foo.bar', 'STRIPE_SECRET', 'stripe.secret', 'RAZORPAY_SECRET', 'SMTP_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'S3_KEY', 'database.password', 'DB_PASSWORD', 'APP_KEY', 'JWT_SECRET', 'webhook.secret', 'encryption_key']);
test('unknown groups are rejected', function (string $group) {
    expect(fn () => app(Update::class)->save(admin18(), $group, ['values' => ['x' => 1], 'versions' => ['x' => 0]]))->toThrow(ValidationException::class);
})->with(['unknown', 'mail', 'database', 'services', 'filesystems']);
test('registry rejects invalid business values atomically', function (string $group, string $key, mixed $value) {
    expect(fn () => save18($group, [$key => $value]))->toThrow(ValidationException::class);
    expect(MarketplaceSetting::count())->toBe(0)->and(MarketplaceSettingEvent::count())->toBe(0);
})->with([
    ['commerce', 'maximum_cart_items', 0], ['commerce', 'maximum_cart_items', 101], ['commerce', 'maximum_cart_items', '1.5'], ['commerce', 'maximum_cart_items', []],
    ['commerce', 'enabled_currencies', []], ['commerce', 'enabled_currencies', ['CAD']], ['commerce', 'enabled_currencies', ['INR', 'INR']], ['commerce', 'enabled_currencies', 'INR'],
    ['general', 'marketplace_name', '<script>'], ['general', 'support_contact_email', 'bad'], ['general', 'business_contact_email', 'bad'], ['general', 'marketplace_name', str_repeat('a', 151)],
    ['reporting', 'timezone', 'Invalid/Zone'], ['reporting', 'timezone', '+05:30'], ['refunds', 'refund_window_days', -1], ['refunds', 'refund_window_days', 3651],
    ['authors', 'commission_percentage', '-1.00'], ['authors', 'commission_percentage', '100.01'], ['authors', 'commission_percentage', '20.001'], ['authors', 'commission_percentage', 20.0], ['authors', 'earning_hold_days', -1], ['authors', 'earning_hold_days', 3651],
    ['payouts', 'minimum_INR', '0.00'], ['payouts', 'minimum_USD', '-1.00'], ['payouts', 'minimum_EUR', '1.001'], ['payouts', 'minimum_GBP', 1.0],
    ['products', 'cover_max_kb', 20481], ['products', 'screenshot_max_kb', 20481], ['products', 'screenshots_max', 1], ['products', 'screenshots_max', 31], ['products', 'package_max_kb', 524289], ['products', 'documentation_max_kb', 51201],
    ['support', 'attachment_max_kb', 10241], ['support', 'attachment_max_kb', 0], ['support', 'attachments_max', 0], ['support', 'attachments_max', 6], ['support', 'allowed_types', []], ['support', 'allowed_types', ['exe']], ['support', 'allowed_types', ['svg']], ['support', 'allowed_types', ['html']],
    ['notifications', 'sender_name', "bad\nname"], ['notifications', 'show_support_contact', 'yes'],
]);
test('typed canonical settings retain exact values', function (string $group, string $key, mixed $value, mixed $expected) {
    save18($group, [$key => $value]);
    expect(app(Settings::class)->value($group, $key))->toBe($expected)->and(MarketplaceSetting::first()->value)->toBe($expected);
})->with([
    ['commerce', 'maximum_cart_items', '4', 4], ['commerce', 'enabled_currencies', ['USD', 'INR'], ['INR', 'USD']], ['refunds', 'refund_window_days', 0, 0],
    ['payouts', 'minimum_INR', '1234.5', '1234.50'], ['payouts', 'minimum_USD', '20.01', '20.01'], ['payouts', 'minimum_EUR', '21.00', '21.00'], ['payouts', 'minimum_GBP', '22.00', '22.00'],
    ['notifications', 'show_support_contact', 1, true], ['support', 'allowed_types', ['pdf', 'png'], ['pdf', 'png']], ['reporting', 'timezone', 'Europe/London', 'Europe/London'], ['general', 'support_contact_email', 'support@example.com', 'support@example.com'], ['general', 'marketplace_name', 'Business Software', 'Business Software'],
]);
test('configuration fallback and DB precedence preserve bootstrap limits', function () {
    config(['commerce.cart_limit' => 12]);
    expect(app(Settings::class)->cartMaximum())->toBe(12);
    save18('commerce', ['maximum_cart_items' => 8]);
    config(['commerce.cart_limit' => 15]);
    expect(app(Settings::class)->cartMaximum())->toBe(8);
});
test('missing production policies remain unavailable rather than inventing rights', function (string $group) {
    app()->instance('env', 'production');
    config(['refunds.window_days' => null, 'payouts.minimums.INR' => null]);
    expect(fn () => app(Settings::class)->value($group, $group === 'refunds' ? 'refund_window_days' : 'minimum_INR'))->toThrow(ValidationException::class);
    app()->instance('env', 'testing');
})->with(['refunds', 'payouts']);
test('section validation rolls back every field and audit', function () {
    expect(fn () => save18('support', ['attachment_max_kb' => 20, 'attachments_max' => 6]))->toThrow(ValidationException::class);
    expect(app(Settings::class)->supportLimits()['size'])->toBe(10240)->and(MarketplaceSettingEvent::count())->toBe(0);
});
test('audit records actor and canonical safe old new values', function () {
    $a = admin18();
    save18('commerce', ['enabled_currencies' => ['USD', 'INR']], $a);
    $e = MarketplaceSettingEvent::first();
    expect($e->getAttribute('actor_user_id'))->toBe($a->id)->and($e->new_value)->toBe(['INR', 'USD'])->and($e->old_value)->toBe(['EUR', 'GBP', 'INR', 'USD']);
});
test('audit models refuse mutation or deletion', function (string $operation) {
    save18('commerce', ['maximum_cart_items' => 5]);
    $e = MarketplaceSettingEvent::first();
    expect(fn () => $operation === 'update' ? $e->forceFill(['key' => 'forged'])->save() : $e->delete())->toThrow(LogicException::class);
})->with(['update', 'delete']);
test('audit actor cannot self-delete retained configuration history', function () {
    $a = admin18();
    save18('commerce', ['maximum_cart_items' => 5], $a);
    $this->actingAs($a)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(User::whereKey($a->id)->exists())->toBeTrue();
});
test('no-op saves do not emit misleading audit history', function () {
    save18('commerce', ['maximum_cart_items' => 50]);
    expect(MarketplaceSettingEvent::count())->toBe(0);
});
test('stale writer cannot silently win and section remains atomic', function () {
    $a = admin18();
    $old = input18('commerce', ['maximum_cart_items' => 8, 'enabled_currencies' => ['INR']]);
    save18('commerce', ['maximum_cart_items' => 6], $a);
    expect(fn () => app(Update::class)->save($a, 'commerce', $old))->toThrow(ValidationException::class);
    expect(app(Settings::class)->cartMaximum())->toBe(6)->and(app(Settings::class)->currencies())->toBe(['EUR', 'GBP', 'INR', 'USD'])->and(MarketplaceSettingEvent::count())->toBe(1);
});
test('browser cannot forge persistence metadata or actor', function (string $field) {
    $a = admin18();
    $v = input18('commerce', ['maximum_cart_items' => 3]);
    expect(fn () => app(Update::class)->save($a, 'commerce', [...$v, $field => 'FORGED']))->toThrow(ValidationException::class);
    expect(MarketplaceSettingEvent::count())->toBe(0);
})->with(['type', 'sensitive', 'group', 'rules', 'updated_by_user_id', 'actor_user_id', 'version']);
test('versions must identify exactly submitted values', function () {
    expect(fn () => app(Update::class)->save(admin18(), 'commerce', ['values' => ['maximum_cart_items' => 3], 'versions' => []]))->toThrow(ValidationException::class);
});
test('rollback removes audit and discards after-commit event', function () {
    $a = admin18();
    $seen = [];
    Event::listen(MarketplaceSettingChanged::class, function ($e) use (&$seen) {
        $seen[] = $e;
    });
    try {
        DB::transaction(function () use ($a) {
            save18('commerce', ['maximum_cart_items' => 6], $a);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }expect($seen)->toBe([])->and(MarketplaceSettingEvent::count())->toBe(0);
});
test('successful setting event is dispatched after the section commit', function () {
    $seen = [];
    Event::listen(MarketplaceSettingChanged::class, function ($e) use (&$seen) {
        $seen[] = $e;
    });
    save18('commerce', ['maximum_cart_items' => 6]);
    expect(count($seen))->toBe(1)->and($seen[0]->newValue)->toBe(6);
});
test('long lived settings instances observe subsequent DB changes without cache', function () {
    $s = app(Settings::class);
    expect($s->cartMaximum())->toBe(50);
    save18('commerce', ['maximum_cart_items' => 6]);
    expect($s->cartMaximum())->toBe(6);
});
test('malformed persisted limits cannot become unlimited', function () {
    (new MarketplaceSetting)->forceFill(['group' => 'support', 'key' => 'attachments_max', 'value' => 99999, 'value_type' => 'integer', 'version' => 1])->save();
    expect(app(Settings::class)->supportLimits()['count'])->toBe(5);
});
test('support allowed types cannot bypass MIME and active-content checks', function () {
    save18('support', ['allowed_types' => ['txt']]);
    $file = UploadedFile::fake()->createWithContent('notes.txt', '<script>bad</script>');
    expect(fn () => app(SupportAttachments::class)->validate([$file]))->toThrow(ValidationException::class);
});
test('support new file count respects setting', function () {
    save18('support', ['attachments_max' => 1]);
    $files = [UploadedFile::fake()->createWithContent('a.txt', 'safe'), UploadedFile::fake()->createWithContent('b.txt', 'safe')];
    expect(fn () => app(SupportAttachments::class)->validate($files))->toThrow(ValidationException::class);
});
test('support narrowed types reject otherwise safe files', function () {
    save18('support', ['allowed_types' => ['pdf']]);
    expect(fn () => app(SupportAttachments::class)->validate([UploadedFile::fake()->createWithContent('a.txt', 'safe')]))->toThrow(ValidationException::class);
});
test('support size setting rejects new oversized attachment', function () {
    save18('support', ['attachment_max_kb' => 1]);
    expect(fn () => app(SupportAttachments::class)->validate([UploadedFile::fake()->createWithContent('a.txt', str_repeat('a', 2048))]))->toThrow(ValidationException::class);
});
test('reporting changes only boundaries and never commercial timestamps', function () {
    $o = EF::order();
    $before = $o->getRawOriginal('paid_at');
    $old = ReportFilters::from(Request::create('/', 'GET', ['period' => 'today']))->sqlStart();
    save18('reporting', ['timezone' => 'America/New_York']);
    $new = ReportFilters::from(Request::create('/', 'GET', ['period' => 'today']));
    expect($new->timezone)->toBe('America/New_York')->and($new->sqlStart())->not->toBe($old)->and($o->fresh()->getRawOriginal('paid_at'))->toBe($before);
});
test('future Orders snapshot refund policy while old purchased rights remain unchanged', function () {
    $old = RF::order();
    save18('refunds', ['refund_window_days' => 14]);
    $new = RF::order();
    expect($old->fresh()->getAttribute('refund_window_days'))->toBe(7)->and($new->getAttribute('refund_window_days'))->toBe(14)->and(RF::request($old)->getAttribute('window_days'))->toBe(7)->and(RF::request($new)->getAttribute('window_days'))->toBe(14);
});
test('requested refund retains its immutable policy after new setting', function () {
    $r = RF::request();
    save18('refunds', ['refund_window_days' => 30]);
    expect($r->fresh()->getAttribute('window_days'))->toBe(7);
});
test('disabled currency blocks new cart additions without removing historical commerce', function () {
    $p = EF::product(null, '100.00', 'EUR');
    $o = EF::order($p);
    save18('commerce', ['enabled_currencies' => ['INR', 'USD']]);
    expect(fn () => EF::order($p))->toThrow(ValidationException::class);
    $r = RF::request($o);
    RF::complete($r);
    expect($o->fresh()->currency->value)->toBe('EUR')->and($r->fresh()->status->value)->toBe('completed');
});
test('new commission policy preserves previous purchased economics and earning dates', function () {
    $old = EF::order();
    $item = $old->items->first();
    $earning = $item->earning;
    $snapshot = $item->getAttributes();
    $available = $earning->getRawOriginal('available_at');
    save18('authors', ['commission_percentage' => '15.25', 'earning_hold_days' => 10]);
    $this->travel(2)->seconds();
    $new = EF::order();
    expect($new->items->first()->getAttribute('commission_rate'))->toBe('15.25')->and($new->items->first()->getAttribute('earning_hold_days'))->toBe(10)->and($item->fresh()->getAttributes())->toBe($snapshot)->and($earning->fresh()->getRawOriginal('available_at'))->toBe($available);
});
test('payout thresholds apply only to new requests even for disabled currency', function () {
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $e = PF::available(null, 'EUR', '100.00');
    $p = PF::request($e->author, '40.00', PF::method($e->author, 'EUR'));
    save18('payouts', ['minimum_EUR' => '90.00']);
    save18('commerce', ['enabled_currencies' => ['INR']]);
    expect($p->fresh()->amount)->toBe('40.00')->and(app(PayoutMinimums::class)->forCurrency('EUR'))->toBe('90.00');
});
test('new product upload limits retain historical artifacts', function () {
    $p = EF::product();
    $paths = ProductArtifact::whereIn('product_release_id', $p->releases()->pluck('id'))->pluck('path')->all();
    expect($paths)->not->toBeEmpty();
    save18('products', ['screenshot_max_kb' => 1, 'package_max_kb' => 1]);
    expect(ProductArtifact::whereIn('product_release_id', $p->releases()->pluck('id'))->pluck('path')->all())->toBe($paths);
    expect(fn () => app(UploadValidation::class)->image(UploadedFile::fake()->image('screen.png', 800, 500)->size(2), ProductMediaType::Screenshot))->toThrow(ValidationException::class);
});
test('existing over-limit carts remain viewable but cannot add or check out', function () {
    $buyer = User::factory()->create()->assignRole('customer');
    $c = app(CartService::class);
    $products = [EF::product(), EF::product()];
    foreach ($products as $p) {
        $c->add($buyer, $p->slug, $p->offers->first()->id);
    }$cart = Cart::where('user_id', $buyer->id)->first();
    save18('commerce', ['maximum_cart_items' => 1]);
    expect(count($c->view($buyer)['items']))->toBe(2)->and($c->view($buyer)['valid'])->toBeFalse();
    expect(fn () => app(Checkout::class)->place($buyer, $cart->checkout_key, CommerceFixtures::billing()))->toThrow(ValidationException::class);
    expect(fn () => $c->add($buyer, $products[0]->slug, $products[0]->offers->first()->id))->toThrow(ValidationException::class);
    $c->remove($buyer, $cart->items->first()->id);
    expect($c->view($buyer)['valid'])->toBeTrue();
});
test('disabled currency existing cart remains viewable but new checkout is blocked', function () {
    $p = EF::product(null, '100.00', 'USD');
    $buyer = User::factory()->create()->assignRole('customer');
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    save18('commerce', ['enabled_currencies' => ['INR']]);
    expect(app(CartService::class)->view($buyer)['valid'])->toBeFalse();
});
test('disabled currency cannot enter new product commercial configuration', function () {
    save18('commerce', ['enabled_currencies' => ['INR']]);
    expect(fn () => app(SaveProduct::class)->create(admin18(), ['currency' => 'EUR'], ProductOrigin::Templaara))->toThrow(ValidationException::class);
});
test('disabled historical currency continues to appear in analytics', function () {
    EF::order(EF::product(null, '100.00', 'USD'));
    save18('commerce', ['enabled_currencies' => ['INR']]);
    $f = ReportFilters::from(Request::create('/'));
    $rows = app(MarketplaceAnalytics::class)->financial($f);
    expect(array_column($rows, 'currency'))->toContain('USD');
});
test('future policy changes do not alter completed refund compensation', function () {
    $o = RF::order();
    $r = RF::request($o);
    RF::complete($r);
    $before = DB::table('refund_compensations')->get()->map(fn ($row) => (array) $row)->all();
    save18('authors', ['commission_percentage' => '5.00']);
    expect(DB::table('refund_compensations')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});
test('payout exactly at configured minimum succeeds and below it fails', function () {
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $e = PF::available();
    $method = PF::method($e->author);
    save18('payouts', ['minimum_INR' => '2000.00']);
    expect(fn () => PF::request($e->author, '1999.99', $method))->toThrow(ValidationException::class);
    expect(PF::request($e->author, '2000.00', $method)->amount)->toBe('2000.00');
});
test('notification display changes do not change infrastructure or user preferences', function () {
    $mail = config('mail');
    $prefs = DB::table('notification_preferences')->count();
    save18('notifications', ['sender_name' => 'Business Software']);
    $n = new MarketplaceNotification('123', ['title' => 'Update', 'message' => 'Safe update', 'action_path' => '/account/orders/EXAMPLE']);
    $message = $n->toMail(admin18());
    expect($message->from[1])->toBe('Business Software')->and(config('mail'))->toBe($mail)->and(DB::table('notification_preferences')->count())->toBe($prefs);
});
test('retained support attachments are unchanged after lowering limits', function () {
    config(['support.attachment_disk' => config('product_files.artifact_disk')]);
    $ticket = SupportFixtures::ticket(null, [UploadedFile::fake()->createWithContent('notes.txt', 'safe retained content')]);
    $before = DB::table('support_attachments')->get()->map(fn ($r) => (array) $r)->all();
    save18('support', ['attachment_max_kb' => 1, 'allowed_types' => ['pdf']]);
    expect(DB::table('support_attachments')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});
test('registry persistence has database uniqueness and version bounds', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL direct-write verification.');
    }save18('commerce', ['maximum_cart_items' => 5]);
    expect(fn () => DB::transaction(function () use ($case) {
        if ($case === 'duplicate') {
            DB::table('marketplace_settings')->insert(['group' => 'commerce', 'key' => 'maximum_cart_items', 'value' => '5', 'value_type' => 'integer', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('marketplace_settings')->update(['version' => 0]);
        }
    }))->toThrow(QueryException::class);
})->with(['duplicate', 'version']);
test('database protects immutable audit and retained actors', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL direct-write verification.');
    }$a = admin18();
    save18('commerce', ['maximum_cart_items' => 5], $a);
    expect(fn () => DB::transaction(fn () => match ($case) {
        'update' => DB::table('marketplace_setting_events')->update(['key' => 'forged']),'delete' => DB::table('marketplace_setting_events')->delete(),'actor' => DB::table('users')->where('id', $a->id)->delete()
    }))->toThrow(QueryException::class);
})->with(['update', 'delete', 'actor']);
test('purchase policy snapshot cannot be directly rewritten', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL direct-write verification.');
    }$o = EF::order();
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $o->id)->update(['refund_window_days' => 90])))->toThrow(QueryException::class);
});
test('new product revisions cannot configure disabled currency', function () {
    $p = EF::product(null, '100.00', 'EUR');
    $a = admin18();
    $s = app(ProductUpdates::class);
    $r = $s->create($a, $p, '1.1.0');
    $old = $r->fresh()->content;
    save18('commerce', ['enabled_currencies' => ['INR']]);
    expect(fn () => $s->save($a, $r, $old))->toThrow(ValidationException::class);
    expect($r->fresh()->content)->toBe($old);
});
test('proposed product screenshots obey configured cap', function () {
    $p = EF::product();
    $a = admin18();
    $s = app(ProductUpdates::class);
    $r = $s->create($a, $p, '1.1.0');
    save18('products', ['screenshots_max' => 2]);
    expect(fn () => $s->upload($a, $r, 'screenshot', UploadedFile::fake()->image('screen.png', 800, 500)))->toThrow(ValidationException::class);
    expect($r->media()->where('type', 'screenshot')->count())->toBe(2);
});
test('support create page exposes effective bounded limits and type subset', function () {
    save18('support', ['attachment_max_kb' => 5, 'attachments_max' => 1, 'allowed_types' => ['pdf']]);
    $u = User::factory()->create()->assignRole('customer');
    $this->actingAs($u)->get(route('account.support.create'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('attachmentMaxKb', 5)->where('attachmentsMax', 1)->where('attachmentTypes', ['pdf']));
});
test('optional public contact can be cleared without null JSON persistence', function () {
    save18('general', ['support_contact_email' => 'support@example.com']);
    save18('general', ['support_contact_email' => null]);
    expect(app(Settings::class)->identity()['support_contact_email'])->toBe('')->and(MarketplaceSetting::where('key', 'support_contact_email')->first()->value)->toBe('');
});
