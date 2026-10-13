<?php

namespace App\Services\Entitlements;

use Illuminate\Validation\ValidationException;

class ActivationIdentity
{
    public function hash(string $value): string
    {
        $key = config('license_activation.identity_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new \LogicException('A stable activation identity key is required.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    public function domain(?string $input): ?string
    {
        if (! $input) {
            return null;
        }
        $host = parse_url(str_contains($input, '://') ? $input : 'https://'.$input, PHP_URL_HOST);
        if (! is_string($host)) {
            $this->invalid();
        }
        $host = rtrim(mb_strtolower($host), '.');
        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (strlen($host) > 253 || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->invalid();
        }

        return $host;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['domain' => 'Supply a valid installation hostname.']);
    }
}
