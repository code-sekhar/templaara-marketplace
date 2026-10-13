<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class Readiness
{
    /** Only bounded reads; no mail, provider requests, queue dispatch or storage writes. */
    public function available(): bool
    {
        try {
            $connection = config('database.connections.'.config('database.default'), []);
            if (($connection['driver'] ?? '') === 'pgsql') {
                // Isolated probe budgets do not shorten commercial transactions/exports.
                $connection['options'][\PDO::ATTR_TIMEOUT] = 3;
                $connection['server_options']['statement_timeout'] = 3000;
                $connection['server_options']['lock_timeout'] = 1000;
                config()->set('database.connections.readiness_probe', $connection);
                try {
                    DB::connection('readiness_probe')->select('SELECT 1');
                } finally {
                    DB::purge('readiness_probe');
                }
            } else {
                DB::select('SELECT 1');
            }
            foreach ($this->redisConnections() as $connection) {
                Redis::connection($connection)->ping();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,string> */
    public function redisConnections(): array
    {
        $names = [];
        $store = config('cache.stores.'.config('cache.default'), []);
        if (($store['driver'] ?? null) === 'redis') {
            $names[] = $store['connection'] ?? 'cache';
            $names[] = $store['lock_connection'] ?? 'default';
        }
        if (config('session.driver') === 'redis') {
            $names[] = config('session.connection') ?: 'default';
        }
        foreach ([config('queue.default'), config('notifications.connection')] as $name) {
            if (config('queue.connections.'.$name.'.driver') === 'redis') {
                $names[] = config('queue.connections.'.$name.'.connection', 'default');
            }
        }

        return array_values(array_unique($names));
    }
}
