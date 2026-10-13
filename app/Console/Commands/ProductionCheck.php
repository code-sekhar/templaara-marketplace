<?php

namespace App\Console\Commands;

use App\Services\Operations\ProductionReadiness;
use Illuminate\Console\Command;

final class ProductionCheck extends Command
{
    protected $signature = 'marketplace:production-check';

    protected $description = 'Read-only production configuration and dependency audit; never proves external launch readiness';

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        $this->table(['Check', 'Result', 'Explanation'], array_map(fn ($key, $value) => [$key, $value['status'], $value['message']], array_keys($checks), $checks));
        $this->warn('External TLS, workers, scheduler, mail, buckets, backups, alerts and provider sandbox verification require operator evidence.');

        return collect($checks)->contains(fn ($check) => $check['status'] === 'FAIL') ? self::FAILURE : self::SUCCESS;
    }
}
