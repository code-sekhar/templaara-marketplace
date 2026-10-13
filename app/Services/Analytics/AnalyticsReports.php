<?php

namespace App\Services\Analytics;

use App\Services\Commerce\Money;
use App\Services\Reviews\ProductRatingSummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AnalyticsReports
{
    public function __construct(private FinancialFacts $facts) {}

    /**
     * Columns are also the exact CSV allow-list.
     *
     * @return array<string,array<string,string>>
     */
    public function definitions(): array
    {
        return [
            'tax' => ['currency' => 'Currency', 'tax_type' => 'Tax classification', 'jurisdiction' => 'Jurisdiction', 'tax_rate' => 'Rate', 'taxable_sales' => 'Taxable invoiced sales', 'tax_collected' => 'Tax invoiced', 'tax_credited' => 'Tax credited', 'net_tax' => 'Net tax'],
            'sales' => ['reference' => 'Order', 'paid_at' => 'Paid date', 'customer' => 'Customer', 'items' => 'Items', 'currency' => 'Currency', 'gross' => 'Gross', 'refunded' => 'Completed refunds', 'retained' => 'Retained gross'],
            'products' => ['product' => 'Product', 'seller' => 'Seller', 'category' => 'Category', 'status' => 'Status', 'currency' => 'Currency', 'units' => 'Paid units', 'orders' => 'Paid orders', 'gross' => 'Gross', 'refunded' => 'Completed refunds', 'retained' => 'Retained gross', 'review_count' => 'Public reviews', 'rating' => 'Rating'],
            'authors' => ['author' => 'Author', 'products' => 'Products', 'currency' => 'Currency', 'units' => 'Paid units', 'gross' => 'Gross', 'retained' => 'Retained gross', 'commission' => 'Commission', 'author_earned' => 'Author earned', 'author_reversed' => 'Refund adjustments', 'net_earned' => 'Net earned in period', 'paid' => 'Paid payouts in period', 'outstanding' => 'Outstanding position at end'],
            'customers' => ['customer' => 'Customer', 'registered_at' => 'Registered', 'orders' => 'Paid orders', 'products' => 'Purchased products', 'currencies' => 'Currencies', 'last_purchase' => 'Last purchase'],
            'refunds' => ['reference' => 'Refund', 'requested_at' => 'Requested', 'customer' => 'Customer', 'products' => 'Products', 'currency' => 'Currency', 'amount' => 'Refund amount', 'status' => 'Status', 'completed_at' => 'Completed'],
            'payouts' => ['reference' => 'Payout', 'author' => 'Author', 'currency' => 'Currency', 'amount' => 'Amount', 'status' => 'Status', 'requested_at' => 'Requested', 'paid_at' => 'Paid'],
            'reviews' => ['product' => 'Product', 'rating' => 'Rating', 'published_at' => 'Published', 'responded' => 'Seller response'],
            'support' => ['reference' => 'Ticket', 'product' => 'Product', 'customer' => 'Customer', 'seller' => 'Seller', 'category' => 'Category', 'priority' => 'Priority', 'status' => 'Status', 'created_at' => 'Created', 'last_activity' => 'Last activity'],
        ];
    }

    /** @return array<string,mixed> */
    public function row(string $report, object $row): array
    {
        $result = [];
        foreach ($this->definitions()[$report] as $key => $label) {
            $value = $row->$key ?? null;
            if (in_array($key, ['taxable_sales', 'tax_collected', 'tax_credited', 'net_tax', 'gross', 'refunded', 'retained', 'commission', 'author_earned', 'author_reversed', 'net_earned', 'paid', 'outstanding', 'amount'], true)) {
                $value = Money::decimal((string) ($value ?? '0'));
            } elseif (in_array($key, ['items', 'units', 'orders', 'review_count'], true) || ($key === 'products' && $report !== 'refunds')) {
                $value = (int) $value;
            } elseif ($key === 'rating' && $value !== null) {
                $value = (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
            } elseif ($key === 'responded') {
                $value = $value ? 'Yes' : 'No';
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function query(string $report, ReportFilters $f): Builder
    {
        return match ($report) {
            'tax' => $this->tax($f),
            'sales' => $this->sales($f),
            'products' => $this->products($f),
            'authors' => $this->authors($f),
            'customers' => $this->customers($f),
            'refunds' => $this->refunds($f),
            'payouts' => $this->payouts($f),
            'reviews' => $this->reviews($f),
            'support' => $this->support($f),
            default => abort(404),
        };
    }

    public function tax(ReportFilters $f): Builder
    {
        $q = DB::table('invoice_items as line')->join('invoices as document', 'document.id', '=', 'line.invoice_id')->join('order_items as purchase', 'purchase.id', '=', 'line.order_item_id')->join('products as p', 'p.id', '=', 'purchase.product_id');
        $q->where('document.issued_at', '>=', $f->sqlStart())->where('document.issued_at', '<', $f->sqlEnd());
        if (isset($f->values['currency'])) {
            $q->where('document.currency', $f->values['currency']);
        }
        $this->productFilters($q, $f, 'p.id', 'purchase.author_user_id', 'purchase.origin');

        return $q->selectRaw("document.currency, line.tax_type, line.jurisdiction, line.tax_rate, SUM(CASE WHEN document.kind='invoice' THEN line.taxable_amount ELSE 0 END) AS taxable_sales, SUM(CASE WHEN document.kind='invoice' THEN line.tax_amount ELSE 0 END) AS tax_collected, SUM(CASE WHEN document.kind='credit_note' THEN line.tax_amount ELSE 0 END) AS tax_credited, SUM(CASE WHEN document.kind='invoice' THEN line.tax_amount ELSE -line.tax_amount END) AS net_tax")
            ->groupBy('document.currency', 'line.tax_type', 'line.jurisdiction', 'line.tax_rate')->orderBy('document.currency')->orderBy('line.tax_type')->orderBy('line.jurisdiction')->orderBy('line.tax_rate');
    }

    public function sales(ReportFilters $f): Builder
    {
        return $this->facts->sales($f)->join('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->selectRaw('o.id AS row_id, o.order_number AS reference, o.paid_at, buyer.name AS customer, COUNT(*) AS items, i.currency, SUM(i.line_total) AS gross, SUM(COALESCE(comp.refund,0)) AS refunded, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained')
            ->groupBy('o.id', 'o.order_number', 'o.paid_at', 'buyer.name', 'i.currency')->orderByDesc('o.paid_at')->orderByDesc('o.id');
    }

    public function products(ReportFilters $f): Builder
    {
        $rating = app(ProductRatingSummary::class)->publicQuery()->toBase()->selectRaw('product_id, COUNT(*) AS review_count, AVG(rating) AS rating')->groupBy('product_id');

        return $this->facts->sales($f)->leftJoin('users as seller', 'seller.id', '=', 'i.author_user_id')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($rating, 'rating_summary', 'rating_summary.product_id', '=', 'p.id')
            ->when(isset($f->values['status']), fn ($q) => $q->where('p.status', $f->values['status']))
            ->selectRaw("p.id AS row_id, p.name AS product, COALESCE(seller.name,'Templaara Official') AS seller, c.name AS category, p.status, i.currency, SUM(i.quantity) AS units, COUNT(DISTINCT o.id) AS orders, SUM(i.line_total) AS gross, SUM(COALESCE(comp.refund,0)) AS refunded, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained, COALESCE(rating_summary.review_count,0) AS review_count, rating_summary.rating")
            ->groupBy('p.id', 'p.name', 'seller.name', 'i.author_user_id', 'c.name', 'p.status', 'i.currency', 'rating_summary.review_count', 'rating_summary.rating')->orderBy('i.currency')->orderByDesc('retained')->orderBy('p.id')->orderBy('i.author_user_id');
    }

    public function authors(ReportFilters $f): Builder
    {
        $sales = $this->facts->sales($f)->whereNotNull('i.author_user_id')->selectRaw('i.author_user_id, i.currency, SUM(i.quantity) AS units, SUM(i.line_total) AS gross, SUM(i.line_total-COALESCE(comp.refund,0)) AS retained, SUM(COALESCE(i.commission_amount,0)) AS commission, SUM(COALESCE(i.author_amount,0)) AS author_earned, SUM(COALESCE(comp.author_reversed,0)) AS author_reversed, SUM(COALESCE(i.author_amount,0)-COALESCE(comp.author_reversed,0)) AS net_earned')->groupBy('i.author_user_id', 'i.currency');
        $payouts = $this->facts->period(DB::table('payout_requests')->where('status', 'paid'), 'paid_at', $f)->selectRaw('author_user_id, currency, SUM(amount) AS paid')->groupBy('author_user_id', 'currency');
        $products = DB::table('products')->selectRaw('owner_id, COUNT(*) AS products')->groupBy('owner_id');

        return DB::query()->fromSub($this->facts->positions($f), 'position')->join('users as u', 'u.id', '=', 'position.author_user_id')
            ->leftJoinSub($sales, 'sales', fn ($j) => $j->on('sales.author_user_id', '=', 'u.id')->on('sales.currency', '=', 'position.currency'))
            ->leftJoinSub($payouts, 'payout', fn ($j) => $j->on('payout.author_user_id', '=', 'u.id')->on('payout.currency', '=', 'position.currency'))
            ->leftJoinSub($products, 'inventory', 'inventory.owner_id', '=', 'u.id')
            // Product/category/origin filters constrain the author cohort; position remains the whole author ledger.
            ->when(isset($f->values['product']) || isset($f->values['category']) || isset($f->values['origin']), fn ($q) => $q->whereNotNull('sales.author_user_id'))
            ->selectRaw('u.id AS row_id, u.name AS author, COALESCE(inventory.products,0) AS products, position.currency, COALESCE(sales.units,0) AS units, COALESCE(sales.gross,0) AS gross, COALESCE(sales.retained,0) AS retained, COALESCE(sales.commission,0) AS commission, COALESCE(sales.author_earned,0) AS author_earned, COALESCE(sales.author_reversed,0) AS author_reversed, COALESCE(sales.net_earned,0) AS net_earned, COALESCE(payout.paid,0) AS paid, position.outstanding')->orderBy('position.currency')->orderByDesc('net_earned')->orderBy('u.id');
    }

    public function customers(ReportFilters $f): Builder
    {
        $concat = DB::getDriverName() === 'pgsql' ? "STRING_AGG(DISTINCT i.currency, ', ' ORDER BY i.currency)" : 'GROUP_CONCAT(DISTINCT i.currency)';
        $sales = $this->facts->items($f)->selectRaw("o.user_id, COUNT(DISTINCT o.id) AS orders, COUNT(DISTINCT i.product_id) AS products, $concat AS currencies, MAX(o.paid_at) AS last_purchase")->groupBy('o.user_id');

        return DB::query()->fromSub($sales, 'sales')->join('users as u', 'u.id', '=', 'sales.user_id')->selectRaw('u.id AS row_id, u.name AS customer, u.created_at AS registered_at, sales.orders, sales.products, sales.currencies, sales.last_purchase')->orderByDesc('sales.last_purchase')->orderBy('u.id');
    }

    public function refunds(ReportFilters $f): Builder
    {
        $concat = DB::getDriverName() === 'pgsql' ? "STRING_AGG(DISTINCT i.product_name, ', ' ORDER BY i.product_name)" : 'GROUP_CONCAT(DISTINCT i.product_name)';
        $q = DB::table('refunds as r')->join('users as u', 'u.id', '=', 'r.customer_user_id')->join('refund_items as ri', 'ri.refund_id', '=', 'r.id')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->join('products as p', 'p.id', '=', 'i.product_id');
        $this->common($q, $f, 'r', 'r.requested_at', 'i.author_user_id', 'i.product_id', 'i.origin');

        return $q->selectRaw("r.id AS row_id, r.reference, r.requested_at, u.name AS customer, $concat AS products, r.currency, SUM(ri.amount) AS amount, r.status, r.completed_at")->groupBy('r.id', 'r.reference', 'r.requested_at', 'u.name', 'r.currency', 'r.status', 'r.completed_at')->orderByDesc('r.requested_at')->orderByDesc('r.id');
    }

    public function payouts(ReportFilters $f, bool $paidPeriod = false): Builder
    {
        $q = DB::table('payout_requests as r')->join('users as u', 'u.id', '=', 'r.author_user_id');
        $this->common($q, $f, 'r', $paidPeriod ? 'r.paid_at' : 'r.requested_at', 'r.author_user_id');
        if ($paidPeriod) {
            $q->where('r.status', 'paid');
        }
        if (isset($f->values['product']) || isset($f->values['category']) || isset($f->values['origin'])) {
            $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('payout_allocations as a')->join('author_earnings as e', 'e.id', '=', 'a.author_earning_id')->join('products as p', 'p.id', '=', 'e.product_id')->whereColumn('a.payout_request_id', 'r.id');
                foreach (['product' => 'e.product_id', 'category' => 'p.category_id', 'origin' => 'p.origin'] as $key => $column) {
                    if (isset($f->values[$key])) {
                        $s->where($column, $f->values[$key]);
                    }
                }
            });
        }

        return $q->selectRaw('r.id AS row_id, r.payout_number AS reference, u.name AS author, r.currency, r.amount, r.status, r.requested_at, r.paid_at')->orderByDesc('r.requested_at')->orderByDesc('r.id');
    }

    public function reviews(ReportFilters $f): Builder
    {
        $q = app(ProductRatingSummary::class)->publicQuery()->toBase()->join('products as p', 'p.id', '=', 'product_reviews.product_id')->leftJoin('product_review_responses as response', 'response.review_id', '=', 'product_reviews.id');
        $this->facts->period($q, 'product_reviews.published_at', $f);
        $this->productFilters($q, $f, 'p.id', 'p.owner_id', 'p.origin');
        if (isset($f->values['currency'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('order_items as purchase')->whereColumn('purchase.id', 'product_reviews.verified_order_item_id')->where('purchase.currency', $f->values['currency']));
        }
        if (isset($f->values['status']) && $f->values['status'] !== 'published') {
            $q->whereRaw('1=0');
        }

        return $q->selectRaw('product_reviews.id AS row_id, p.id AS product_id, p.name AS product, product_reviews.rating, product_reviews.published_at, CASE WHEN response.id IS NULL THEN 0 ELSE 1 END AS responded')->orderByDesc('product_reviews.published_at')->orderByDesc('product_reviews.id');
    }

    public function support(ReportFilters $f): Builder
    {
        $q = DB::table('support_tickets as r')->join('users as u', 'u.id', '=', 'r.customer_user_id')->leftJoin('users as seller', 'seller.id', '=', 'r.seller_user_id')->join('products as p', 'p.id', '=', 'r.product_id');
        $this->common($q, $f, 'r', 'r.created_at', 'r.seller_user_id', 'r.product_id', 'p.origin', false);
        if (isset($f->values['currency'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('order_items as item')->whereColumn('item.id', 'r.order_item_id')->where('item.currency', $f->values['currency']));
        }

        return $q->selectRaw("r.id AS row_id, r.ticket_number AS reference, r.product_name AS product, u.name AS customer, COALESCE(seller.name,'Templaara Support') AS seller, r.category, r.priority, r.status, r.created_at, r.last_message_at AS last_activity")->orderByDesc('r.last_message_at')->orderByDesc('r.id');
    }

    private function common(Builder $q, ReportFilters $f, string $alias, string $date, string $author, ?string $product = null, ?string $origin = null, bool $currency = true): void
    {
        $this->facts->period($q, $date, $f);
        if ($currency && isset($f->values['currency'])) {
            $q->where($alias.'.currency', $f->values['currency']);
        }
        if (isset($f->values['status'])) {
            $q->where($alias.'.status', $f->values['status']);
        }
        $this->productFilters($q, $f, $product, $author, $origin);
    }

    private function productFilters(Builder $q, ReportFilters $f, ?string $product, string $author, ?string $origin): void
    {
        foreach (['product' => $product, 'author' => $author, 'origin' => $origin, 'category' => $product ? 'p.category_id' : null] as $key => $column) {
            if ($column && isset($f->values[$key])) {
                $q->where($column, $f->values[$key]);
            }
        }
    }
}
