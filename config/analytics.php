<?php

return [
    // All boundaries are business-calendar dates in this timezone, never browser time.
    'timezone' => env('MARKETPLACE_REPORTING_TIMEZONE', 'Asia/Kolkata'),
    'maximum_days' => 366,
    'page_size' => 20,
    'export_chunk_size' => 500,
];
