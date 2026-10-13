<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Models\User;
use App\Services\Commerce\Money;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Reviews\ProductRatingSummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MarketplaceAnalytics
{
    public function __construct(private FinancialFacts $facts, private AnalyticsReports $reports) {}

    /** @return array<string,mixed> */
    public function dashboard(ReportFilters $f): array
    {
        $current = $this->financial($f);
        $previous = collect($this->financial($f->previous()))->keyBy('currency');
        foreach ($current as &$r) {
            $r['comparison'] = $this->change($r['gross'], $previous->get($r['currency'])['gross'] ?? '0.00');
        }
        unset($r);
        $cohort = $this->facts->items($f)->select('o.user_id')->distinct();
        $lifetime = $this->facts->paidOrders()->where('o.paid_at', '<', $f->sqlEnd())->selectRaw('o.user_id, COUNT(DISTINCT o.id) AS orders, MIN(o.paid_at) AS first_paid')->groupBy('o.user_id');
        $buyers = DB::query()->fromSub($lifetime, 'lifetime')->joinSub($cohort, 'cohort', 'cohort.user_id', '=', 'lifetime.user_id')->selectRaw('COUNT(*) AS purchasing, SUM(CASE WHEN lifetime.orders>=2 THEN 1 ELSE 0 END) AS repeat, SUM(CASE WHEN lifetime.first_paid>=? THEN 1 ELSE 0 END) AS first_time', [$f->sqlStart()])->first();
        $live = app(MarketplaceQuery::class)->visible();
        foreach (['product' => 'products.id', 'category' => 'products.category_id', 'author' => 'products.owner_id', 'origin' => 'products.origin', 'currency' => 'products.currency'] as $key => $column) {
            if (isset($f->values[$key])) {
                $live->where($column, $f->values[$key]);
            }
        }
        $inventory = (clone $live)->selectRaw("COUNT(*) AS live, SUM(CASE WHEN origin='author' THEN 1 ELSE 0 END) AS author, SUM(CASE WHEN origin='templaara' THEN 1 ELSE 0 END) AS official, COUNT(DISTINCT owner_id) AS active_sellers")->first();
        $reviews = $this->reports->reviews($f)->reorder();
        $reviewRows = DB::query()->fromSub($reviews, 'review')->selectRaw('COUNT(*) AS count, SUM(rating) AS rating_sum, COUNT(DISTINCT product_id) AS products, SUM(CASE WHEN responded=0 THEN 1 ELSE 0 END) AS unanswered')->first();
        $distribution = DB::query()->fromSub($reviews, 'review')->selectRaw('rating, COUNT(*) AS count')->groupBy('rating')->pluck('count', 'rating')->all();
        $support = $this->reports->support($f)->reorder();
        $supportCounts = DB::query()->fromSub($support, 'ticket')->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->all();
        $refunds = $this->reports->refunds($f)->reorder();
        $refundCounts = DB::query()->fromSub($refunds, 'refund')->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->all();
        $authorRows = $this->reports->authors($f)->reorder();
        $topProducts = $this->reports->products($f);
        $topUnits = (clone $topProducts)->reorder()->orderByDesc('units')->orderBy('row_id')->orderBy('i.currency');
        $topRated = app(ProductRatingSummary::class)->publicQuery()->whereIn('product_id', (clone $live)->select('products.id'))->selectRaw('product_id, COUNT(*) AS count, SUM(rating) AS total')->groupBy('product_id')->orderByRaw('AVG(rating) DESC')->orderByDesc('count')->orderBy('product_id')->limit(5)->get();
        $names = Product::whereIn('id', $topRated->pluck('product_id'))->pluck('name', 'id');
        $categorySales = $this->facts->sales($f)->join('categories as c', 'c.id', '=', 'p.category_id')->selectRaw('c.name, i.currency, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained')->groupBy('c.name', 'i.currency')->orderBy('i.currency')->orderByDesc('retained')->orderBy('c.name')->limit(10)->get()->map(fn ($r) => ['name' => $r->name, 'currency' => $r->currency, 'retained' => Money::decimal((string) $r->retained)])->all();
        $industrySales = $this->facts->sales($f)->join('industry_product as ip', 'ip.product_id', '=', 'p.id')->join('industries as sector', 'sector.id', '=', 'ip.industry_id')->selectRaw('sector.name, i.currency, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained')->groupBy('sector.name', 'i.currency')->orderBy('i.currency')->orderByDesc('retained')->orderBy('sector.name')->limit(10)->get()->map(fn ($r) => ['name' => $r->name, 'currency' => $r->currency, 'retained' => Money::decimal((string) $r->retained)])->all();
        $queues = [
            ['label' => 'Author applications', 'count' => DB::table('author_profiles')->whereIn('status', ['submitted', 'under_review'])->count(), 'url' => '/admin/author-applications', 'permission' => 'authors.view'],
            ['label' => 'Product reviews', 'count' => DB::table('products')->whereIn('status', ['submitted', 'under_review'])->count(), 'url' => '/admin/product-reviews', 'permission' => 'products.review'],
            ['label' => 'Product updates', 'count' => DB::table('product_revisions')->whereIn('status', ['submitted', 'under_review'])->count(), 'url' => '/admin/product-updates', 'permission' => 'products.review'],
            ['label' => 'Refund actions', 'count' => DB::table('refunds')->whereIn('status', ['requested', 'approved', 'processing'])->count(), 'url' => '/admin/refunds', 'permission' => 'refunds.view'],
            ['label' => 'Payout actions', 'count' => DB::table('payout_requests')->whereIn('status', ['requested', 'approved', 'processing'])->count(), 'url' => '/admin/payouts', 'permission' => 'payouts.view'],
            ['label' => 'Escalated support', 'count' => DB::table('support_tickets')->where('is_escalated', true)->whereIn('status', ['awaiting_seller', 'awaiting_customer'])->count(), 'url' => '/admin/support?escalated=1', 'permission' => 'support.view'],
        ];

        return [
            'financial' => $current,
            'customers' => ['registered' => User::role('customer')->count(), 'registered_in_period' => $this->facts->period(User::role('customer')->toBase(), 'users.created_at', $f)->count(), 'purchasing' => (int) $buyers->purchasing, 'repeat' => (int) $buyers->repeat, 'first_time' => (int) $buyers->first_time],
            'products' => ['live' => (int) $inventory->getAttribute('live'), 'author' => (int) $inventory->getAttribute('author'), 'official' => (int) $inventory->getAttribute('official'), 'changes_requested' => DB::table('products')->where('status', 'changes_requested')->count(), 'categories' => (clone $live)->join('categories as c', 'c.id', '=', 'products.category_id')->selectRaw('c.name, COUNT(*) AS count')->groupBy('c.name')->orderBy('c.name')->get()->map(fn ($r) => ['name' => $r->getAttribute('name'), 'count' => (int) $r->getAttribute('count')])->all(), 'industries' => (clone $live)->join('industry_product as ip', 'ip.product_id', '=', 'products.id')->join('industries as sector', 'sector.id', '=', 'ip.industry_id')->selectRaw('sector.name, COUNT(*) AS count')->groupBy('sector.name')->orderBy('sector.name')->get()->map(fn ($r) => ['name' => $r->getAttribute('name'), 'count' => (int) $r->getAttribute('count')])->all()],
            'authors' => ['approved' => DB::table('author_profiles')->where('status', 'approved')->count(), 'active_selling' => (int) $inventory->getAttribute('active_sellers'), 'with_paid_sale' => $this->facts->items($f)->whereNotNull('i.author_user_id')->distinct()->count('i.author_user_id'), 'with_outstanding' => DB::query()->fromSub($this->facts->positions($f), 'position')->where('outstanding', '<>', 0)->distinct()->count('author_user_id')],
            'refunds' => $this->states($refundCounts, ['requested', 'approved', 'processing', 'completed', 'failed', 'cancelled']),
            'reviews' => [...app(ProductRatingSummary::class)->totals((int) $reviewRows->count, (string) ($reviewRows->rating_sum ?? '0')), 'products' => (int) $reviewRows->products, 'unanswered' => (int) $reviewRows->unanswered, 'distribution' => array_map(fn ($n) => (int) ($distribution[$n] ?? 0), range(1, 5))],
            'support' => [...$this->states($supportCounts, ['awaiting_seller', 'awaiting_customer', 'resolved', 'closed']), 'escalated' => $this->supportExtra($f)->where('r.is_escalated', true)->count(), 'high_priority_active' => $this->supportExtra($f)->where('r.priority', 'high')->whereIn('r.status', ['awaiting_seller', 'awaiting_customer'])->count(), 'categories' => DB::query()->fromSub($support, 'ticket')->selectRaw('category, COUNT(*) AS count')->groupBy('category')->orderBy('category')->get()->all(), 'priorities' => DB::query()->fromSub($support, 'ticket')->selectRaw('priority, COUNT(*) AS count')->groupBy('priority')->orderBy('priority')->get()->all()],
            'queues' => $queues,
            'topProducts' => $this->rank($topProducts, 'retained')->map(fn ($r) => $this->reports->row('products', $r))->all(),
            'topUnits' => $this->rank($topUnits, 'units')->map(fn ($r) => $this->reports->row('products', $r))->all(),
            'topRated' => $topRated->map(fn ($r) => ['product' => $names[$r->product_id], ...app(ProductRatingSummary::class)->totals((int) $r->getAttribute('count'), (string) $r->getAttribute('total'))])->all(),
            'topAuthors' => $this->rank($authorRows, 'retained')->map(fn ($r) => $this->reports->row('authors', $r))->all(),
            'topAuthorEarnings' => $this->rank($authorRows, 'net_earned')->map(fn ($r) => $this->reports->row('authors', $r))->all(),
            'categoryPerformance' => $categorySales, 'industryPerformance' => $industrySales,
            'charts' => $this->charts($f),
        ];
    }

    /**
     * Rank within each currency; never compare INR amounts to USD amounts.
     *
     * @param  'retained'|'units'|'net_earned'  $metric
     * @return Collection<int,\stdClass>
     */
    private function rank(Builder $query, string $metric): Collection
    {
        $ranked = DB::query()->fromSub($query->reorder(), 'performance')->selectRaw("performance.*, ROW_NUMBER() OVER (PARTITION BY currency ORDER BY $metric DESC, row_id ASC) AS currency_rank");

        return DB::query()->fromSub($ranked, 'ranking')->where('currency_rank', '<=', 5)->orderBy('currency')->orderBy('currency_rank')->get();
    }

    private function supportExtra(ReportFilters $f): Builder
    {
        return DB::table('support_tickets as r')->whereIn('r.id', $this->reports->support($f)->reorder()->select('r.id'));
    }

    /**
     * @param  array<string,mixed>  $counts
     * @param  array<int,string>  $states
     * @return array<string,int>
     */
    private function states(array $counts, array $states): array
    {
        return array_combine($states, array_map(fn ($s) => (int) ($counts[$s] ?? 0), $states));
    }

    /** @return array<string,mixed> */
    public function change(string $current, string $previous): array
    {
        $diff = BigDecimal::of($current)->minus($previous);

        return ['absolute' => Money::decimal((string) $diff), 'percentage' => BigDecimal::of($previous)->isZero() ? null : (string) $diff->multipliedBy(100)->dividedBy($previous, 2, RoundingMode::HalfUp), 'new' => BigDecimal::of($previous)->isZero() && ! BigDecimal::of($current)->isZero()];
    }

    /** @return array<int,array<string,mixed>> */
    public function financial(ReportFilters $f): array
    {
        $positions = DB::query()->fromSub($this->facts->positions($f), 'position')->selectRaw('currency, SUM(outstanding) AS outstanding')->groupBy('currency')->get()->keyBy('currency');
        $payouts = $this->reports->payouts($f, true)->reorder()->select('r.currency')->selectRaw('SUM(r.amount) AS paid')->groupBy('r.currency')->get()->keyBy('currency');
        $refunds = $this->facts->items($f, false)->join('refund_items as refund_item', 'refund_item.order_item_id', '=', 'i.id')->join('refund_compensations as rc', 'rc.refund_item_id', '=', 'refund_item.id')->join('refunds as refund', 'refund.id', '=', 'refund_item.refund_id')->where('refund.status', 'completed');
        $refunds = $this->facts->period($refunds, 'refund.completed_at', $f)->selectRaw('i.currency, SUM(rc.gross_amount) AS completed_in_period')->groupBy('i.currency')->get()->keyBy('currency');
        $totals = $this->facts->totals($f)->get()->keyBy('currency');
        $currencies = array_unique([...$totals->keys()->all(), ...$positions->keys()->all(), ...$refunds->keys()->all(), ...$payouts->keys()->all()]);
        sort($currencies);
        $rows = [];
        foreach ($currencies as $currency) {
            if (isset($f->values['currency']) && $f->values['currency'] !== $currency) {
                continue;
            }
            $r = $totals->get($currency);
            $row = ['currency' => $currency];
            foreach (['gross', 'refunded', 'retained', 'commission', 'author_earned', 'commission_reversed', 'author_reversed', 'official_gross', 'official_refunded'] as $key) {
                $row[$key] = Money::decimal((string) ($r->$key ?? '0'));
            }
            foreach (['paid_orders', 'paid_items', 'purchasers', 'units', 'refunded_items', 'affected_orders'] as $key) {
                $row[$key] = (int) ($r->$key ?? 0);
            }
            $row['aov'] = $row['paid_orders'] ? (string) BigDecimal::of($row['gross'])->dividedBy((string) $row['paid_orders'], 2, RoundingMode::HalfUp) : null;
            $row['net_commission'] = Money::decimal((string) BigDecimal::of($row['commission'])->minus($row['commission_reversed']));
            $row['official_retained'] = Money::decimal((string) BigDecimal::of($row['official_gross'])->minus($row['official_refunded']));
            $row['platform_retained'] = Money::decimal((string) BigDecimal::of($row['official_retained'])->plus($row['net_commission']));
            $row['net_author_earned'] = Money::decimal((string) BigDecimal::of($row['author_earned'])->minus($row['author_reversed']));
            $row['paid_payouts'] = Money::decimal((string) ($payouts->get($currency)->paid ?? '0'));
            $row['outstanding'] = Money::decimal((string) ($positions->get($currency)->outstanding ?? '0'));
            $row['completed_refunds_in_period'] = Money::decimal((string) ($refunds->get($currency)->completed_in_period ?? '0'));
            $row['item_refund_rate'] = $this->rate($row['refunded_items'], $row['paid_items']);
            $row['order_affected_rate'] = $this->rate($row['affected_orders'], $row['paid_orders']);
            $rows[] = $row;
        }

        return $rows;
    }

    private function rate(int $part, int $total): ?string
    {
        return $total ? (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy((string) $total, 2, RoundingMode::HalfUp) : null;
    }

    /** @return array<string,mixed> */
    public function charts(ReportFilters $f): array
    {
        $monthly = $f->start->diffInDays($f->end) > 90;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $bucket = function (string $column) use ($f, $monthly): array {
            $expression = match ($column) {
                'o.paid_at' => DB::getDriverName() === 'pgsql'
                    ? ($monthly ? "to_char(o.paid_at AT TIME ZONE ?, 'YYYY-MM')" : "to_char(o.paid_at AT TIME ZONE ?, 'YYYY-MM-DD')")
                    : ($monthly ? "strftime('%Y-%m', o.paid_at, ?)" : "strftime('%Y-%m-%d', o.paid_at, ?)"),
                'r.completed_at' => DB::getDriverName() === 'pgsql'
                    ? ($monthly ? "to_char(r.completed_at AT TIME ZONE ?, 'YYYY-MM')" : "to_char(r.completed_at AT TIME ZONE ?, 'YYYY-MM-DD')")
                    : ($monthly ? "strftime('%Y-%m', r.completed_at, ?)" : "strftime('%Y-%m-%d', r.completed_at, ?)"),
                'users.created_at' => DB::getDriverName() === 'pgsql'
                    ? ($monthly ? "to_char((users.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM')" : "to_char((users.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD')")
                    : ($monthly ? "strftime('%Y-%m', users.created_at, ?)" : "strftime('%Y-%m-%d', users.created_at, ?)"),
                default => throw new \LogicException('Unknown chart date.'),
            };

            return [$expression, [DB::getDriverName() === 'pgsql' ? $f->timezone : $f->start->format('P')]];
        };
        [$sql, $bindings] = $bucket('o.paid_at');
        $sales = $this->facts->items($f)->selectRaw("$sql AS bucket, i.currency, SUM(i.line_total) AS gross, COUNT(DISTINCT o.id) AS orders, SUM(COALESCE(i.commission_amount,0)) AS commission, SUM(COALESCE(i.author_amount,0)) AS author_earned", $bindings)->groupBy('bucket', 'i.currency')->orderBy('bucket')->orderBy('i.currency')->get();
        [$sql, $bindings] = $bucket('r.completed_at');
        $refund = $this->facts->items($f, false)->join('refund_items as ri', 'ri.order_item_id', '=', 'i.id')->join('refund_compensations as rc', 'rc.refund_item_id', '=', 'ri.id')->join('refunds as r', 'r.id', '=', 'ri.refund_id')->where('r.status', 'completed');
        $refunds = $this->facts->period($refund, 'r.completed_at', $f)->selectRaw("$sql AS bucket, i.currency, SUM(rc.gross_amount) AS amount", $bindings)->groupBy('bucket', 'i.currency')->get();
        [$sql, $bindings] = $bucket('users.created_at');
        $registrations = $this->facts->period(User::role('customer')->toBase(), 'users.created_at', $f)->selectRaw("$sql AS bucket, COUNT(*) AS count", $bindings)->groupBy('bucket')->get()->keyBy('bucket');
        $labels = [];
        for ($d = $f->start; $d->lessThan($f->end); $d = $monthly ? $d->startOfMonth()->addMonth() : $d->addDay()) {
            $labels[] = $d->format($format);
        }
        $currencies = array_unique([...$sales->pluck('currency')->all(), ...$refunds->pluck('currency')->all()]);
        sort($currencies);
        $series = [];
        foreach ($currencies as $currency) {
            $s = $sales->where('currency', $currency)->keyBy('bucket');
            $r = $refunds->where('currency', $currency)->keyBy('bucket');
            $series[] = ['currency' => $currency, 'points' => array_map(fn ($label) => ['bucket' => $label, 'gross' => Money::decimal((string) ($s->get($label)->gross ?? '0')), 'orders' => (int) ($s->get($label)->orders ?? 0), 'commission' => Money::decimal((string) ($s->get($label)->commission ?? '0')), 'author_earned' => Money::decimal((string) ($s->get($label)->author_earned ?? '0')), 'refunds' => Money::decimal((string) ($r->get($label)->amount ?? '0'))], $labels)];
        }

        return ['granularity' => $monthly ? 'monthly' : 'daily', 'labels' => $labels, 'series' => $series, 'newCustomers' => array_map(fn ($label) => ['bucket' => $label, 'count' => (int) ($registrations->get($label)->count ?? 0)], $labels)];
    }
}
