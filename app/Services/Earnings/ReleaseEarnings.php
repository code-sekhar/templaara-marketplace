<?php

namespace App\Services\Earnings;

use App\Enums\AuthorEarningStatus;
use App\Events\Commerce\AuthorEarningAvailable;
use App\Models\AuthorEarning;
use App\Models\AuthorEarningLedgerEntry;
use App\Models\RefundCompensation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseEarnings
{
    public function release(AuthorEarning $target): bool
    {
        return DB::transaction(function () use ($target): bool {
            User::whereKey($target->author_user_id)->lockForUpdate()->firstOrFail();
            $e = AuthorEarning::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $now = now();
            if (RefundCompensation::where('author_earning_id', $e->id)->exists() || $e->status !== AuthorEarningStatus::Pending || $e->getAttribute('available_at')->isAfter($now)) {
                return false;
            }
            $e->forceFill(['status' => AuthorEarningStatus::Available, 'released_at' => $now])->save();
            (new AuthorEarningLedgerEntry)->forceFill(['author_earning_id' => $e->id, 'author_user_id' => $e->author_user_id, 'currency' => $e->getAttribute('currency'), 'type' => 'earning_available', 'amount' => $e->getAttribute('author_amount'), 'occurred_at' => $now])->save();

            AuthorEarningAvailable::dispatch($e->id);

            return true;
        }, 3);
    }

    /** @return array{released:int,errors:int} */
    public function run(): array
    {
        $counts = ['released' => 0, 'errors' => 0];
        AuthorEarning::where('status', 'pending')->where('available_at', '<=', now())->chunkById(100, function ($rows) use (&$counts) {
            foreach ($rows as $e) {
                try {
                    $counts['released'] += (int) $this->release($e);
                } catch (\Throwable $ex) {
                    $counts['errors']++;
                    Log::error('Earning release requires operational review.', ['earning_id' => $e->id, 'exception' => $ex::class]);
                }
            }
        });

        return $counts;
    }
}
