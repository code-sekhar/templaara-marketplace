<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportWorkflow;
use Illuminate\Support\Str;

class SupportFixtures
{
    public static function order(?User $author = null, bool $official = false, ?int $days = 180): Order
    {
        $p = $official ? CommerceFixtures::product() : EarningFixtures::product($author);
        $p->offers->first()->forceFill(['support_period_days' => $days])->save();

        return ReviewFixtures::purchase($p);
    }

    /** @return array<string,mixed> */
    public static function input(): array
    {
        return ['request_key' => (string) Str::uuid(), 'subject' => 'Help installing inventory software', 'category' => 'installation', 'priority' => 'normal', 'body' => 'Please help configure our inventory installation.'];
    }

    /** @param array<mixed> $files */
    public static function ticket(?Order $o = null, array $files = []): SupportTicket
    {
        $o ??= self::order();

        return app(SupportWorkflow::class)->create(User::findOrFail($o->user_id), $o->items->first(), self::input(), $files);
    }

    /** @return array<string,mixed> */
    public static function reply(string $body = 'Here are the installation details.'): array
    {
        return ['request_key' => (string) Str::uuid(), 'body' => $body];
    }
}
