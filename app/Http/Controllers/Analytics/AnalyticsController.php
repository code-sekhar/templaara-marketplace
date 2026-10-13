<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\AnalyticsReports;
use App\Services\Analytics\MarketplaceAnalytics;
use App\Services\Analytics\ReportCsv;
use App\Services\Analytics\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AnalyticsController extends Controller
{
    public function dashboard(Request $request, MarketplaceAnalytics $analytics): Response
    {
        Inertia::encryptHistory();
        $filters = ReportFilters::from($request);

        return Inertia::render('analytics/Dashboard', [...$filters->presentation(), 'facets' => $this->facets($filters), 'analytics' => $analytics->dashboard($filters)]);
    }

    public function index(AnalyticsReports $reports): Response
    {
        return Inertia::render('analytics/Index', ['reports' => array_keys($reports->definitions())]);
    }

    public function show(Request $request, string $report, AnalyticsReports $reports): Response
    {
        abort_unless(isset($reports->definitions()[$report]), 404);
        Inertia::encryptHistory();
        $filters = ReportFilters::from($request);
        $rows = $reports->query($report, $filters)->paginate((int) config('analytics.page_size'))->withQueryString()->through(fn ($row) => $reports->row($report, $row));

        return Inertia::render('analytics/Report', [...$filters->presentation(), 'facets' => $this->facets($filters), 'report' => $report, 'statuses' => ReportFilters::statuses($report), 'columns' => $reports->definitions()[$report], 'rows' => $rows]);
    }

    public function export(Request $request, string $report, AnalyticsReports $reports, ReportCsv $csv): StreamedResponse
    {
        abort_unless(isset($reports->definitions()[$report]), 404);

        return $csv->response($report, ReportFilters::from($request));
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function facets(ReportFilters $filters): array
    {
        $result = [];
        foreach (['product' => 'products', 'author' => 'users', 'category' => 'categories'] as $key => $table) {
            $query = DB::table($table)->select('id', 'name');
            if ($key === 'author') {
                $query->whereIn('id', User::role('author')->select('users.id'));
            }
            $rows = $query->orderBy('name')->orderBy('id')->limit(100)->get();
            if (isset($filters->values[$key]) && ! $rows->contains('id', $filters->values[$key])) {
                $selected = DB::table($table)->select('id', 'name')->where('id', $filters->values[$key])->first();
                if ($selected) {
                    $rows->push($selected);
                }
            }
            $result[$key] = $rows->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all();
        }

        return $result;
    }
}
