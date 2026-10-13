<?php

// Development samples apply only in local/testing. Production must configure each currency explicitly.
return ['identity_key' => env('PAYOUT_IDENTITY_KEY') ?: env('APP_KEY'), 'minimums' => ['INR' => env('PAYOUT_MINIMUM_INR'), 'USD' => env('PAYOUT_MINIMUM_USD'), 'EUR' => env('PAYOUT_MINIMUM_EUR'), 'GBP' => env('PAYOUT_MINIMUM_GBP')], 'development_minimums' => ['INR' => '1000.00', 'USD' => '20.00', 'EUR' => '20.00', 'GBP' => '20.00']];
