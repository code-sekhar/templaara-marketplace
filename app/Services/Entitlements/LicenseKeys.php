<?php

namespace App\Services\Entitlements;

class LicenseKeys
{
    public function generate(): string
    {
        return 'TMP-'.implode('-', str_split(strtoupper(bin2hex(random_bytes(20))), 5));
    }

    public function fingerprint(string $key): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $key)));
    }
}
