<?php

namespace App\Console\Commands;

use App\Services\Development\DevelopmentDemoCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class DemoCatalog extends Command
{
    protected $signature = 'marketplace:demo-catalog {--remove : Hide registered demo products while retaining immutable history} {--refresh-artwork : Publish safe development artwork revisions for registered unpurchased demos}';

    protected $description = 'Create a fictional, development-only software catalog through the normal domain workflow';

    public function handle(DevelopmentDemoCatalog $catalog): int
    {
        if (! app()->environment(['local', 'testing', 'development'])) {
            $this->error('Demo catalog is restricted to local, testing and development environments.');

            return self::FAILURE;
        }
        try {
            $count = $catalog->run((bool) $this->option('remove'), (bool) $this->option('refresh-artwork'));
            $this->info($this->option('remove') ? "Hidden $count registered demo products. Immutable history and private artifacts are retained." : "$count fictional demo products are available. No purchases or reviews were created.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Demo catalog could not finish safely. '.($exception instanceof \RuntimeException && ! $exception instanceof QueryException ? $exception->getMessage() : 'Check catalog prerequisites and the registered demo state; no provider call was made.'));

            return self::FAILURE;
        }
    }
}
