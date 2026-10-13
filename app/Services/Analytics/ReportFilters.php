<?php

namespace App\Services\Analytics;

use App\Services\Settings\MarketplaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReportFilters
{
    /** @param array<string,mixed> $values */
    public function __construct(public readonly array $values, public readonly CarbonImmutable $start, public readonly CarbonImmutable $end, public readonly string $timezone) {}

    /** @return array<int,string> */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'products' => ['draft', 'submitted', 'under_review', 'changes_requested', 'rejected', 'approved', 'published'],
            'refunds' => ['requested', 'approved', 'processing', 'completed', 'failed', 'cancelled'],
            'payouts' => ['requested', 'approved', 'processing', 'paid', 'failed', 'cancelled'],
            'reviews' => ['published'],
            'support' => ['awaiting_seller', 'awaiting_customer', 'resolved', 'closed'],
            default => [],
        };
    }

    public static function from(Request $request): self
    {
        $v = $request->validate([
            'period' => ['nullable', Rule::in(['today', '7days', '30days', '90days', 'month', 'last_month', 'year', 'custom'])],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'product' => ['nullable', 'integer', 'min:1', 'exists:products,id'],
            'author' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'category' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'origin' => ['nullable', Rule::in(['author', 'templaara'])],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'under_review', 'changes_requested', 'rejected', 'approved', 'published', 'requested', 'processing', 'completed', 'failed', 'cancelled', 'paid', 'awaiting_seller', 'awaiting_customer', 'resolved', 'closed'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ]);
        $report = $request->route('report');
        if (isset($v['status']) && is_string($report) && ! in_array($v['status'], self::statuses($report), true)) {
            throw ValidationException::withMessages(['status' => 'Choose a status available for this report.']);
        }
        $timezone = app(MarketplaceSettings::class)->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $period = $v['period'] ?? '30days';
        [$start, $end] = match ($period) {
            'today' => [$today, $today->addDay()],
            '7days' => [$today->subDays(6), $today->addDay()],
            '90days' => [$today->subDays(89), $today->addDay()],
            'month' => [$today->startOfMonth(), $today->addDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()],
            'year' => [$today->startOfYear(), $today->addDay()],
            'custom' => [CarbonImmutable::parse($v['from'], $timezone), CarbonImmutable::parse($v['to'], $timezone)->addDay()],
            default => [$today->subDays(29), $today->addDay()],
        };
        if ($start->diffInDays($end) > config('analytics.maximum_days')) {
            throw ValidationException::withMessages(['to' => 'Choose a reporting range of at most '.config('analytics.maximum_days').' days.']);
        }

        return new self([...array_filter($v, fn ($value) => $value !== null && $value !== ''), 'period' => $period, 'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString()], $start, $end, $timezone);
    }

    public function previous(): self
    {
        $days = (int) $this->start->diffInDays($this->end);

        return new self($this->values, $this->start->subDays($days), $this->start, $this->timezone);
    }

    public function sqlStart(): string
    {
        return $this->start->utc()->format(DB::getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');
    }

    public function sqlEnd(): string
    {
        return $this->end->utc()->format(DB::getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    public function presentation(): array
    {
        return ['filters' => $this->values, 'timezone' => $this->timezone, 'start' => $this->start->toIso8601String(), 'endExclusive' => $this->end->toIso8601String()];
    }
}
