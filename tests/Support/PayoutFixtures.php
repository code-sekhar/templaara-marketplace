<?php

namespace Tests\Support;

use App\Models\AuthorEarning;
use App\Models\AuthorPayoutMethod;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Payouts\PayoutMethods;
use App\Services\Payouts\PayoutWorkflow;
use Illuminate\Support\Str;

class PayoutFixtures
{
    /** @return array<string,mixed> */
    public static function bank(string $currency = 'INR', string $number = '123456789012'): array
    {
        return ['display_label' => 'Business Bank', 'currency' => $currency, 'country_code' => $currency === 'INR' ? 'IN' : 'US', 'account_holder_name' => 'Business Author', 'bank_name' => 'Example Bank', 'account_number' => $number, 'routing_code' => $currency === 'INR' ? 'ABCD0123456' : '021000021', 'iban' => null, 'swift_bic' => null, 'is_default' => true];
    }

    public static function method(User $author, string $currency = 'INR'): AuthorPayoutMethod
    {
        return app(PayoutMethods::class)->save($author, self::bank($currency));
    }

    public static function available(?User $author = null, string $currency = 'INR', string $gross = '6250.00'): AuthorEarning
    {
        $author ??= User::factory()->create()->assignRole('author');
        $p = EarningFixtures::product($author, $gross, $currency);
        $o = EarningFixtures::order($p);
        $e = AuthorEarning::where('order_id', $o->id)->firstOrFail();
        app(ReleaseEarnings::class)->release($e);

        return $e->fresh();
    }

    /** @return array<string,string> */
    public static function input(AuthorPayoutMethod $method, string $amount = '4000.00'): array
    {
        return ['currency' => $method->getAttribute('currency'), 'method' => $method->getAttribute('public_uuid'), 'amount' => $amount, 'request_key' => (string) Str::uuid()];
    }

    public static function request(User $author, string $amount = '4000.00', ?AuthorPayoutMethod $method = null): PayoutRequest
    {
        $method ??= self::method($author);

        return app(PayoutWorkflow::class)->request($author, self::input($method, $amount));
    }

    public static function process(PayoutRequest $p, ?User $admin = null): User
    {
        $admin ??= User::factory()->create()->assignRole('admin');
        $s = app(PayoutWorkflow::class);
        $s->transition($admin, $p, 'approve', asAdmin: true);
        $s->transition($admin, $p, 'process', asAdmin: true);

        return $admin;
    }
}
