<?php

namespace App\Services\Invoicing;

use Illuminate\Support\Facades\DB;

final class InvoiceNumber
{
    public function next(string $kind, string $prefix): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Invoice allocation requires a transaction.');
        }
        $year = now('UTC')->year;
        // Shared annual counter prevents collisions when administrators change either prefix.
        $kind = 'document';
        DB::table('invoice_sequences')->insertOrIgnore(['kind' => $kind, 'year' => $year, 'last_number' => 1]);
        $row = DB::table('invoice_sequences')->where(['kind' => $kind, 'year' => $year])->lockForUpdate()->first();
        $number = (int) $row->last_number;
        if ($number >= 999999) {
            throw new \LogicException('Invoice number capacity reached.');
        }
        DB::table('invoice_sequences')->where(['kind' => $kind, 'year' => $year])->update(['last_number' => $number + 1]);

        return $prefix.'-'.substr((string) $year, -2).'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
