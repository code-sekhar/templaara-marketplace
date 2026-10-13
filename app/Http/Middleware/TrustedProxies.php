<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

final class TrustedProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = array_filter(config('production.trusted_proxies', []), self::valid(...));
        $this->setTrustedProxyIpAddressesToSpecificIps($request, array_values($proxies));
    }

    public static function valid(string $proxy): bool
    {
        $parts = explode('/', $proxy);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $maximum = str_contains($parts[0], ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= $maximum;
    }
}
