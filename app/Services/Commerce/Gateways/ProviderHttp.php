<?php

namespace App\Services\Commerce\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class ProviderHttp
{
    public static function stripe(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1')->withToken(config('commerce.stripe.secret_key'))
            ->withHeaders(['Stripe-Version' => '2025-03-31.basil'])->connectTimeout(3)->timeout(12)->acceptJson()->asForm()->withoutRedirecting();
    }

    public static function razorpay(): PendingRequest
    {
        return Http::baseUrl('https://api.razorpay.com/v1')->withBasicAuth(config('commerce.razorpay.key_id'), config('commerce.razorpay.key_secret'))
            ->connectTimeout(3)->timeout(12)->acceptJson()->withoutRedirecting();
    }

    /**
     * GET may be retried twice; POST is never automatically retried.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function send(PendingRequest $http, string $method, string $path, array $data = []): array
    {
        try {
            if ($method === 'get') {
                $http->retry(2, 100, throw: false);
            }
            $response = $http->{$method}($path, $data);
            $result = $response->json();
            if (! $response->successful() || ! is_array($result)) {
                throw new ProviderUnavailable;
            }

            return $result;
        } catch (\Throwable) {
            throw new ProviderUnavailable;
        }
    }

    /** @return array<string,mixed> */
    public static function body(string $body): array
    {
        abort_if(strlen($body) > 65536, 413);
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Malformed payment event.');
        }
        abort_unless(is_array($data), 400, 'Malformed payment event.');

        return $data;
    }

    public static function reference(mixed $value, string $prefix): string
    {
        if (! is_string($value) || ! preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9_]{1,90}$/D', $value)) {
            throw new ProviderUnavailable;
        }

        return $value;
    }
}
