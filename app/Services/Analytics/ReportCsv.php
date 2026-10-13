<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsv
{
    public function __construct(private AnalyticsReports $reports) {}

    public static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        // Strip ASCII controls (including TAB/CR/LF); prefix formulas even after whitespace.
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        if (preg_match('/^\s*[=+\-@]/u', $text)) {
            $text = "'".$text;
        }

        return $text;
    }

    public function response(string $report, ReportFilters $filters): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Export stream unavailable.');
            }
            // One MVCC snapshot keeps offset chunks consistent during concurrent domain writes.
            $connection = DB::connection();
            $ownsTransaction = $connection->transactionLevel() === 0;
            if ($ownsTransaction) {
                $connection->beginTransaction();
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_values($this->reports->definitions()[$report]), ',', '"', '');
                $page = 1;
                $size = (int) config('analytics.export_chunk_size');
                do {
                    $rows = $this->reports->query($report, $filters)->forPage($page++, $size)->get();
                    foreach ($rows as $row) {
                        fputcsv($stream, array_map(self::safeCell(...), $this->reports->row($report, $row)), ',', '"', '');
                    }
                } while ($rows->count() === $size && ! connection_aborted());
                if ($ownsTransaction) {
                    $connection->commit();
                }
            } finally {
                if ($ownsTransaction && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                fclose($stream);
            }
        }, 'templaara-'.$report.'-'.$filters->values['from'].'-'.$filters->values['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
