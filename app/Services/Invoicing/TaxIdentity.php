<?php

namespace App\Services\Invoicing;

final class TaxIdentity
{
    public const GSTIN = '/^(?:0[1-9]|[12][0-9]|3[0-8])[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D';

    /** @return list<string> */
    public static function stateCodes(): array
    {
        return array_map(fn ($i) => str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 38));
    }
}
