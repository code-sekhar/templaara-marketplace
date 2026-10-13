<?php

use App\Enums\AuthorApplicationAction;
use App\Enums\ProductWorkflowAction;
use App\Models\AuthorEarning;
use App\Models\CommissionPolicy;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Analytics\AnalyticsReports;
use App\Services\Analytics\MarketplaceAnalytics;
use App\Services\Analytics\ReportCsv;
use App\Services\Analytics\ReportFilters;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Commerce\Money;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutWorkflow;
use App\Services\Products\ProductUpdates;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Reviews\ReviewWorkflow;
use App\Services\Support\SupportWorkflow;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AuthorFixtures;
use Tests\Support\CommerceFixtures as CF;
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
    config()->set('analytics.timezone', 'Asia/Kolkata');
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('mail.default', 'array');
    config()->set('support.attachment_disk', config('product_files.artifact_disk'));
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
});
function filters17(array $v = []): ReportFilters
{
    return ReportFilters::from(Request::create('/', 'GET', $v));
}
function finance17(array $v = []): array
{
    return app(MarketplaceAnalytics::class)->financial(filters17($v));
}
function report17(string $r, array $v = []): array
{
    $s = app(AnalyticsReports::class);

    return $s->query($r, filters17($v))->get()->map(fn ($row) => $s->row($r, $row))->all();
}
function admin17(): User
{
    return User::factory()->create()->assignRole('admin');
}
function dashboard17(array $v = []): array
{
    return app(MarketplaceAnalytics::class)->dashboard(filters17($v));
}

it('requires authentication on every analytics surface', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/admin/dashboard', '/admin/reports', '/admin/reports/sales', '/admin/reports/sales/export']);
it('denies ordinary roles on dashboard and exports', function (string $role, string $url) {
    $u = User::factory()->create()->assignRole($role);
    $this->actingAs($u)->get($url)->assertForbidden();
})->with(['customer', 'author', 'reviewer'])->with(['/admin/dashboard', '/admin/reports/sales/export']);
it('uses permission authority without an admin role', function () {
    $u = User::factory()->create()->assignRole('customer')->givePermissionTo('reports.view');
    $this->actingAs($u)->get('/admin/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('analytics/Dashboard')->where('analytics.financial', []));
});
it('denies an admin after reports permission is revoked', function () {
    $u = admin17();
    $u->roles->first()->revokePermissionTo('reports.view');
    $this->actingAs($u)->get('/admin/dashboard')->assertForbidden();
});
it('provides private no-store analytics responses', function () {
    $this->actingAs(admin17())->get('/admin/reports')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});
it('rejects arbitrary reports and export columns', function (string $suffix) {
    $this->actingAs(admin17())->get('/admin/reports/passwords'.$suffix)->assertNotFound();
})->with(['', '/export']);
it('returns a clean empty state for every report', function (string $r) {
    expect(report17($r))->toBe([]);
    $this->actingAs(admin17())->get('/admin/reports/'.$r)->assertOk()->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
})->with(['sales', 'products', 'authors', 'customers', 'refunds', 'payouts', 'reviews', 'support']);
it('does not invent currencies or rating in a fresh dashboard', function () {
    $d = dashboard17();
    expect($d['financial'])->toBe([])->and($d['reviews']['average'])->toBeNull()->and($d['reviews']['count'])->toBe(0)->and($d['products']['live'])->toBe(0);
});

it('validates untrusted URL filters before querying', function (array $input, string $field) {
    $this->actingAs(admin17())->getJson('/admin/reports/sales?'.http_build_query($input))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'bad currency' => [['currency' => 'AUD'], 'currency'], 'currency SQL' => [['currency' => "INR' OR 1=1--"], 'currency'], 'bad origin' => [['origin' => 'platform'], 'origin'], 'bad status' => [['status' => 'invented'], 'status'], 'bad preset' => [['period' => 'all'], 'period'], 'bad from' => [['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'], 'from'], 'bad to' => [['period' => 'custom', 'from' => '2026-01-01', 'to' => 'yesterday'], 'to'], 'missing from' => [['period' => 'custom', 'to' => '2026-03-01'], 'from'], 'missing to' => [['period' => 'custom', 'from' => '2026-01-01'], 'to'], 'reversed' => [['period' => 'custom', 'from' => '2026-03-02', 'to' => '2026-03-01'], 'to'], 'too long' => [['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01'], 'to'], 'forged product' => [['product' => 999999], 'product'], 'forged author' => [['author' => 999999], 'author'], 'forged category' => [['category' => 999999], 'category'], 'SQL product' => [['product' => '1 OR 1=1'], 'product'], 'array author' => [['author' => [1]], 'author'], 'bad page' => [['page' => 0], 'page'], 'large page' => [['page' => 100001], 'page'],
]);
it('defines business calendar boundaries deterministically', function (string $period, string $start, string $end) {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00+00'));
    $f = filters17(['period' => $period]);
    expect($f->start->toDateString())->toBe($start)->and($f->end->toDateString())->toBe($end)->and($f->timezone)->toBe('Asia/Kolkata');
})->with([
    ['today', '2026-10-06', '2026-10-07'], ['7days', '2026-09-30', '2026-10-07'], ['30days', '2026-09-07', '2026-10-07'], ['90days', '2026-07-09', '2026-10-07'], ['month', '2026-10-01', '2026-10-07'], ['last_month', '2026-09-01', '2026-10-01'], ['year', '2026-01-01', '2026-10-07'],
]);
it('uses inclusive custom dates and exclusive next midnight', function () {
    $f = filters17(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-07']);
    expect($f->end->toDateString())->toBe('2026-01-08')->and($f->start->utc()->toIso8601String())->toBe('2025-12-31T18:30:00+00:00');
});
it('handles timezone midnight independent of browser input', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 19:00:00+00'));
    $f = filters17(['period' => 'today', 'timezone' => 'America/New_York']);
    expect($f->start->toDateString())->toBe('2026-10-06');
});
it('compares the immediately preceding equal calendar period', function () {
    $f = filters17(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31']);
    expect($f->previous()->end->equalTo($f->start))->toBeTrue()->and((int) $f->previous()->start->diffInDays($f->previous()->end))->toBe(31);
});
it('returns honest exact growth values', function (string $now, string $prev, string $absolute, ?string $percent, bool $new) {
    expect(app(MarketplaceAnalytics::class)->change($now, $prev))->toBe(['absolute' => $absolute, 'percentage' => $percent, 'new' => $new]);
})->with([['0', '0', '0.00', null, false], ['100', '0', '100.00', null, true], ['150', '100', '50.00', '50.00', false], ['25', '100', '-75.00', '-75.00', false], ['0', '100', '-100.00', '-100.00', false]]);
it('uses zero-filled deterministic chart buckets', function (string $period, int $count, string $granularity) {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00+00'));
    $c = app(MarketplaceAnalytics::class)->charts(filters17(['period' => $period]));
    expect($c['labels'])->toHaveCount($count)->and($c['granularity'])->toBe($granularity)->and($c['series'])->toBe([]);
})->with([['today', 1, 'daily'], ['7days', 7, 'daily'], ['90days', 90, 'daily'], ['year', 10, 'monthly']]);

it('counts exact verified paid snapshots once', function () {
    $o = EF::order();
    $r = finance17()[0];
    expect($r['gross'])->toBe('5000.00')->and($r['paid_orders'])->toBe(1)->and($r['paid_items'])->toBe(1)->and($r['aov'])->toBe('5000.00')->and($r['commission'])->toBe('1000.00')->and($r['author_earned'])->toBe('4000.00');
    expect(report17('sales')[0]['reference'])->toBe($o->order_number);
});
it('excludes unpaid and failed attempts from commercial sales', function (bool $fail) {
    $o = EF::order(paid: false);
    if ($fail) {
        EN::pay($o, 'failed');
    } expect(finance17())->toBe([])->and(report17('sales'))->toBe([]);
})->with([false, true]);
it('does not rewrite sales when current offer economics change', function () {
    $p = EF::product();
    EF::order($p);
    $p->offers->first()->forceFill(['price' => '9000.00'])->save();
    expect(finance17()[0]['gross'])->toBe('5000.00')->and(report17('products')[0]['gross'])->toBe('5000.00');
});
it('keeps every supported currency independent', function (string $currency) {
    EF::order(EF::product(null, '1234.56', $currency));
    $r = finance17();
    expect($r)->toHaveCount(1)->and($r[0]['currency'])->toBe($currency)->and($r[0]['gross'])->toBe('1234.56');
})->with(['INR', 'USD', 'EUR', 'GBP']);
it('separates chart series and currency-filtered reports', function () {
    EF::order(EF::product(null, '5000.00', 'INR'));
    EF::order(EF::product(null, '123.45', 'USD'));
    expect(finance17())->toHaveCount(2)->and(finance17(['currency' => 'USD'])[0]['gross'])->toBe('123.45')->and(report17('sales', ['currency' => 'USD']))->toHaveCount(1);
    expect(app(MarketplaceAnalytics::class)->charts(filters17())['series'])->toHaveCount(2);
});
it('only subtracts completed refunds', function (string $status) {
    $o = RF::order();
    $r = RF::request($o);
    $w = app(RefundWorkflow::class);
    $a = admin17();
    if (in_array($status, ['approved', 'processing', 'failed', 'completed'])) {
        $w->transition($a, $r, 'approve', admin: true);
    } if (in_array($status, ['processing', 'failed', 'completed'])) {
        $w->transition($a, $r, 'process', admin: true);
    } if ($status === 'completed') {
        $w->transition($a, $r, 'complete', ['external_reference' => 'REFUND-VERIFIED'], true);
    } if ($status === 'failed') {
        $w->transition($a, $r, 'fail', ['reason' => 'Settlement failed at provider'], true);
    } if ($status === 'cancelled') {
        $w->transition($a, $r, 'cancel', ['reason' => 'Customer cancelled request'], true);
    } $v = finance17()[0];
    expect($v['gross'])->toBe('5000.00')->and($v['refunded'])->toBe($status === 'completed' ? '5000.00' : '0.00')->and($v['retained'])->toBe($status === 'completed' ? '0.00' : '5000.00');
    expect(dashboard17()['refunds'][$status])->toBe(1);
})->with(['requested', 'approved', 'processing', 'completed', 'failed', 'cancelled']);
it('attributes a partial order refund only to the exact purchased item', function () {
    $o = RF::mixed();
    $ids = $o->items->pluck('id')->all();
    $r = app(RefundWorkflow::class)->request(User::find($o->user_id), $o, RF::input($o, [$ids[0]]));
    RF::complete($r);
    $v = finance17()[0];
    expect($v['paid_items'])->toBe(3)->and($v['refunded_items'])->toBe(1)->and($v['affected_orders'])->toBe(1)->and($v['item_refund_rate'])->toBe('33.33')->and($v['order_affected_rate'])->toBe('100.00');
    $r = report17('products', ['product' => $o->items[1]->product_id]);
    expect($r[0]['refunded'])->toBe('0.00');
});
it('reports completed exact commission and author reversals', function () {
    $o = RF::order();
    RF::complete(RF::request($o));
    $r = finance17()[0];
    expect($r['commission_reversed'])->toBe('1000.00')->and($r['author_reversed'])->toBe('4000.00')->and($r['net_commission'])->toBe('0.00')->and($r['net_author_earned'])->toBe('0.00')->and($r['completed_refunds_in_period'])->toBe('5000.00');
});
it('does not invent platform commission or author earnings', function () {
    EF::order(CF::product());
    $r = finance17()[0];
    expect($r['commission'])->toBe('0.00')->and($r['author_earned'])->toBe('0.00')->and(report17('authors'))->toBe([])->and(report17('products')[0]['seller'])->toBe('Templaara Official');
});
it('tracks payout state without turning reservations into paid money', function (string $state) {
    $e = PF::available();
    $u = User::find($e->author_user_id);
    $p = PF::request($u);
    if (in_array($state, ['processing', 'paid'])) {
        $a = PF::process($p);
    } if ($state === 'paid') {
        app(PayoutWorkflow::class)->transition($a, $p, 'pay', ['external_reference' => 'BANK-VERIFIED'], true);
    } $r = finance17()[0];
    expect($r['paid_payouts'])->toBe($state === 'paid' ? '4000.00' : '0.00')->and($r['outstanding'])->toBe($state === 'paid' ? '1000.00' : '5000.00');
    $b = app(FinancialBalances::class)->all($u->id)[0];
    expect($r['outstanding'])->toBe(Money::decimal((string) BigDecimal::of($b['net_earned'])->minus($b['paid'])));
})->with(['requested', 'processing', 'paid']);
it('records a post-paid refund as negative outstanding recovery', function () {
    $e = PF::available();
    $u = User::find($e->author_user_id);
    $p = PF::request($u);
    $a = PF::process($p);
    app(PayoutWorkflow::class)->transition($a, $p, 'pay', ['external_reference' => 'BANK-VERIFIED'], true);
    RF::complete(RF::request($e->order));
    expect(finance17()[0]['outstanding'])->toBe('-4000.00')->and(report17('authors')[0]['outstanding'])->toBe('-4000.00');
});
it('counts buyers by distinct paid orders and historical first purchase', function () {
    $p = EF::product();
    $buyer = User::factory()->create()->assignRole('customer');
    VF::purchase($p, $buyer);
    VF::purchase($p, $buyer);
    $d = dashboard17();
    expect($d['customers']['purchasing'])->toBe(1)->and($d['customers']['repeat'])->toBe(1)->and($d['customers']['first_time'])->toBe(1)->and($d['financial'][0]['paid_orders'])->toBe(2)->and(report17('customers')[0]['orders'])->toBe(2);
});
it('counts public inventory with all Phase 6 dependencies', function () {
    $p = EF::product();
    $official = CF::product();
    Product::factory()->create();
    $d = dashboard17();
    expect($d['products']['live'])->toBe(2)->and($d['products']['author'])->toBe(1)->and($d['products']['official'])->toBe(1);
    $p->forceFill(['is_active' => false])->save();
    expect(dashboard17()['products']['live'])->toBe(1);
});
it('excludes stale published products without public media', function () {
    Product::factory()->create(['status' => 'published']);
    expect(dashboard17()['products']['live'])->toBe(0);
});
it('reports public-qualified review distribution and excludes moderation', function (string $state) {
    $r = VF::review(rating: 4);
    if ($state === 'hidden') {
        app(ReviewWorkflow::class)->action(admin17(), $r, 'hide', ['reason' => 'Review moderation reason']);
    } if ($state === 'withdrawn') {
        app(ReviewWorkflow::class)->action(User::find($r->customer_user_id), $r, 'withdraw');
    } if ($state === 'refunded') {
        RF::complete(RF::request(OrderItem::find($r->verified_order_item_id)->order));
    } $d = dashboard17();
    expect($d['reviews']['count'])->toBe($state === 'published' ? 1 : 0)->and($d['reviews']['average'])->toBe($state === 'published' ? '4.00' : null)->and($d['reviews']['distribution'][3])->toBe($state === 'published' ? 1 : 0);
})->with(['published', 'hidden', 'withdrawn', 'refunded']);
it('reports support statuses without message content', function () {
    $o = SF::order();
    $t = SF::ticket($o);
    $d = dashboard17();
    expect($d['support']['awaiting_seller'])->toBe(1)->and(report17('support')[0]['reference'])->toBe($t->ticket_number);
    app(SupportWorkflow::class)->reply(User::find($t->seller_user_id), $t, SF::reply('PRIVATE-BODY-NEVER-IN-REPORT'), mode: 'author');
    expect(dashboard17()['support']['awaiting_customer'])->toBe(1)->and(json_encode(report17('support')))->not->toContain('PRIVATE-BODY');
});
it('counts active high-priority escalations', function () {
    $t = SF::ticket();
    app(SupportWorkflow::class)->transition(admin17(), $t, 'escalate', ['reason' => 'Issue requires administrator intervention'], mode: 'admin');
    app(SupportWorkflow::class)->transition(admin17(), $t, 'priority', ['priority' => 'high'], mode: 'admin');
    $d = dashboard17();
    expect($d['support']['escalated'])->toBe(1)->and($d['support']['high_priority_active'])->toBe(1);
});

it('applies shared sales and product filters', function (string $key) {
    $p = EF::product();
    EF::order($p);
    EF::order(EF::product());
    $v = match ($key) {
        'product' => $p->id,'category' => $p->category_id,'author' => $p->owner_id,'origin' => 'templaara'
    };
    $count = $key === 'origin' ? 0 : 1;
    expect(report17('sales', [$key => $v]))->toHaveCount($count)->and(report17('products', [$key => $v]))->toHaveCount($count);
})->with(['product', 'category', 'author', 'origin']);
it('filters refund, payout, review and support rows with shared filters', function (string $report) {
    if ($report === 'refunds') {
        $o = RF::order();
        RF::request($o);
    } elseif ($report === 'payouts') {
        $e = PF::available();
        PF::request(User::find($e->author_user_id));
        $o = $e->order;
    } elseif ($report === 'reviews') {
        $o = VF::purchase();
        VF::review($o);
    } else {
        $o = SF::order();
        SF::ticket($o);
    } expect(report17($report, ['product' => $o->items->first()->product_id]))->toHaveCount(1)->and(report17($report, ['currency' => 'USD']))->toBe([]);
})->with(['refunds', 'payouts', 'reviews', 'support']);
it('preserves pagination filters and deterministic row order', function () {
    config()->set('analytics.page_size', 1);
    EF::order();
    EF::order();
    $this->actingAs(admin17())->get('/admin/reports/sales?currency=INR&period=7days')->assertInertia(fn (Assert $p) => $p->where('rows.total', 2)->where('rows.next_page_url', fn ($s) => str_contains($s, 'currency=INR') && str_contains($s, 'period=7days')));
    $a = report17('sales');
    expect($a)->toBe(report17('sales'))->and($a[0]['reference'])->not->toBe($a[1]['reference']);
});
it('rejects state filters not matching a report state', function () {
    EF::order();
    expect(report17('products', ['status' => 'draft']))->toBe([]);
});
it('sanitizes dangerous CSV cells', function (string $value, string $expected) {
    expect(ReportCsv::safeCell($value))->toBe($expected);
})->with([['=SUM(A1)', '\'=SUM(A1)'], ['+cmd', '\'+cmd'], ['-cmd', '\'-cmd'], ['@evil', '\'@evil'], ["\t=cmd", "' =cmd"], ["\r\n+cmd", "'  +cmd"], ['  =cmd', "'  =cmd"], ["normal\ntext", 'normal text'], ['தமிழ்', 'தமிழ்'], ['123.45', '123.45']]);
it('streams each report using only its explicit CSV projection', function (string $report) {
    $this->actingAs(admin17());
    $response = $this->get('/admin/reports/'.$report.'/export');
    $response->assertOk()->assertDownload();
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    expect($response->streamedContent())->toStartWith("\xEF\xBB\xBF")->not->toContain('email', 'license_key', 'method_snapshot', 'provider_payload', 'body');
})->with(['sales', 'products', 'authors', 'customers', 'refunds', 'payouts', 'reviews', 'support']);
it('exports same filtered rows across bounded chunks', function () {
    config()->set('analytics.export_chunk_size', 1);
    $p = EF::product();
    $a = EF::order($p);
    $b = EF::order($p);
    $other = EF::order();
    $this->actingAs(admin17());
    $r = $this->get('/admin/reports/sales/export?product='.$p->id);
    $csv = $r->streamedContent();
    expect(substr_count($csv, $a->order_number))->toBe(1)->and(substr_count($csv, $b->order_number))->toBe(1)->and($csv)->not->toContain($other->order_number);
});
it('omits private fields from loaded report rows and dashboard', function () {
    $o = SF::order();
    SF::ticket($o);
    VF::review($o);
    app(ReleaseEarnings::class)->release(AuthorEarning::where('order_id', $o->id)->first());
    $p = PF::request(User::find($o->items->first()->author_user_id));
    $d = json_encode(dashboard17());
    foreach (['sales', 'products', 'authors', 'customers', 'payouts', 'reviews', 'support'] as $r) {
        $json = json_encode(report17($r));
        expect($json)->not->toContain('email', 'password', 'license_key', 'method_snapshot', 'details_encrypted', 'private_path', 'customer_reason', 'body', 'provider_payload');
    } expect($d)->not->toContain('license_key', 'details_encrypted', 'provider_payload', 'customer_reason');
});
it('keeps analytics queries bounded independent of list length', function () {
    EF::order();
    DB::enableQueryLog();
    dashboard17();
    $first = count(DB::getQueryLog());
    DB::disableQueryLog();
    EF::order();
    DB::flushQueryLog();
    DB::enableQueryLog();
    dashboard17();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($second)->toBe($first)->and($second)->toBeLessThan(65);
});
it('does not mutate notifications or domain balances while reading', function () {
    EF::order();
    $tables = ['orders', 'order_items', 'payments', 'author_earnings', 'author_earning_ledger_entries', 'notifications', 'notification_deliveries'];
    $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
    dashboard17();
    foreach (array_keys(app(AnalyticsReports::class)->definitions()) as $r) {
        report17($r);
    } expect(array_map(fn ($t) => DB::table($t)->count(), $tables))->toBe($before);
});

it('keeps older-sale refund activity distinct from the selected sales cohort', function () {
    CommissionPolicy::first()->forceFill(['effective_from' => '2026-07-01'])->save();
    config()->set('refunds.window_days', 365);
    $this->travelTo(CarbonImmutable::parse('2026-08-05 10:00:00+00'));
    $o = RF::order();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00+00'));
    RF::complete(RF::request($o));
    $oct = finance17(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])[0];
    expect($oct['gross'])->toBe('0.00')->and($oct['refunded'])->toBe('0.00')->and($oct['completed_refunds_in_period'])->toBe('5000.00');
    $aug = finance17(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'])[0];
    expect($aug['gross'])->toBe('5000.00')->and($aug['refunded'])->toBe('0.00')->and($aug['retained'])->toBe('5000.00');
});
it('respects inclusive start and exclusive end for verified sales', function (string $time, int $expected) {
    CommissionPolicy::first()->forceFill(['effective_from' => '2026-10-01'])->save();
    $this->travelTo(CarbonImmutable::parse($time));
    // Timestamp fixtures must represent the intended UTC instant, regardless of the
    // developer server's default timezone. Reads below run in a different session zone.
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
    }
    EF::order();
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Kolkata'");
    }
    $r = report17('sales', ['period' => 'custom', 'from' => '2026-10-06', 'to' => '2026-10-06']);
    expect($r)->toHaveCount($expected);
})->with([['2026-10-05 18:29:59+00', 0], ['2026-10-05 18:30:00+00', 1], ['2026-10-06 18:29:59+00', 1], ['2026-10-06 18:30:00+00', 0]]);
it('excludes prior buyers from first-time period metrics', function () {
    CommissionPolicy::first()->forceFill(['effective_from' => '2026-09-01'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-09-03 10:00:00+00'));
    $p = EF::product();
    $buyer = User::factory()->create()->assignRole('customer');
    VF::purchase($p, $buyer);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00+00'));
    VF::purchase($p, $buyer);
    $d = dashboard17(['period' => 'month']);
    expect($d['customers']['first_time'])->toBe(0)->and($d['customers']['repeat'])->toBe(1)->and($d['customers']['purchasing'])->toBe(1);
});
it('separates currency-safe product and author ranking groups', function () {
    EF::order(EF::product(null, '9999.00', 'INR'));
    EF::order(EF::product(null, '10.00', 'USD'));
    $d = dashboard17();
    foreach (['topProducts', 'topAuthors', 'topAuthorEarnings'] as $key) {
        expect(array_column($d[$key], 'currency'))->toBe(['INR', 'USD']);
    }
});
it('does not expose row IDs or commercial secrets through DTO columns', function (string $report) {
    $columns = app(AnalyticsReports::class)->definitions()[$report];
    expect(array_keys($columns))->not->toContain('row_id', 'id', 'user_id', 'email', 'license_secret', 'license_hash', 'path', 'disk', 'provider_payload', 'body', 'admin_note', 'method_snapshot_encrypted', 'billing_address', 'notification_data');
})->with(['sales', 'products', 'authors', 'customers', 'refunds', 'payouts', 'reviews', 'support']);
it('rejects mismatched status on report and matching CSV filters', function (string $suffix) {
    $this->actingAs(admin17())->getJson('/admin/reports/payouts'.$suffix.'?status=published')->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(['', '/export']);
it('uses stable public product identity to count reviews on same-name products', function () {
    VF::review(VF::purchase(EF::product()), 1);
    VF::review(VF::purchase(EF::product()), 5);
    $d = dashboard17();
    expect($d['reviews']['products'])->toBe(2)->and($d['reviews']['average'])->toBe('3.00')->and($d['reviews']['distribution'])->toBe([1, 0, 0, 0, 1]);
});
it('removes answered reviews from unanswered count without exposing response', function () {
    $r = VF::review();
    app(ReviewWorkflow::class)->action(User::find($r->product->owner_id), $r, 'respond', ['body' => 'PRIVATE SELLER RESPONSE']);
    expect(dashboard17()['reviews']['unanswered'])->toBe(0)->and(report17('reviews')[0]['responded'])->toBe('Yes')->and(json_encode(report17('reviews')))->not->toContain('PRIVATE SELLER RESPONSE');
});
it('tracks resolved and closed support without inventing response SLA', function () {
    $t = SF::ticket();
    $s = app(SupportWorkflow::class);
    $a = admin17();
    $s->transition($a, $t, 'resolve', mode: 'admin');
    expect(dashboard17()['support']['resolved'])->toBe(1);
    $s->transition($a, $t, 'close', ['reason' => 'Issue resolved and administratively closed'], mode: 'admin');
    expect(dashboard17()['support']['closed'])->toBe(1)->and(dashboard17()['support'])->not->toHaveKey('sla');
});
it('counts author application queue and approved authors from retained workflow', function () {
    $a = AuthorFixtures::submitted();
    $staff = admin17();
    $w = app(AuthorWorkflow::class);
    $w->transition($staff, $a->profile, AuthorApplicationAction::Start, $a->id);
    expect(dashboard17()['queues'][0]['count'])->toBe(1);
    $w->transition($staff, $a->profile, AuthorApplicationAction::Approve, $a->id);
    expect(dashboard17()['authors']['approved'])->toBe(1)->and(dashboard17()['queues'][0]['count'])->toBe(0);
});
it('counts product revisions awaiting review without changing live inventory', function () {
    $p = EF::product();
    $actor = User::find($p->owner_id);
    $s = app(ProductUpdates::class);
    $r = $s->create($actor, $p, '1.1.0');
    $s->release($actor, $r, ['version' => '1.1.0', 'changelog' => 'Improved business workflow.']);
    $s->upload($actor, $r, 'source_package', FF::zip());
    $s->transition($actor, $r, ProductWorkflowAction::Submit);
    expect(dashboard17()['queues'][2]['count'])->toBe(1)->and(dashboard17()['products']['live'])->toBe(1);
});
it('separates official retained software gross from author commissions', function () {
    $official = EF::order(CF::product());
    EF::order();
    $v = finance17()[0];
    expect($v['official_gross'])->toBe((string) $official->items->first()->line_total)->and($v['platform_retained'])->toBe(Money::sum([$v['official_gross'], '1000.00']));
    RF::complete(RF::request($official));
    $v = finance17()[0];
    expect($v['official_retained'])->toBe('0.00')->and($v['platform_retained'])->toBe('1000.00')->and($v['commission_reversed'])->toBe('0.00');
});
