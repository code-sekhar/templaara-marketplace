<?php

return [
    'schema' => env('PRODUCTION_DB_SCHEMA', 'public'),
    'trusted_proxies' => array_values(array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')))),
    'csp_mode' => env('CSP_MODE', 'enforced'),
    'image_origins' => array_values(array_filter(explode(',', (string) env('CSP_IMAGE_ORIGINS', '')))),
    'multi_node' => (bool) env('PRODUCTION_MULTI_NODE', false),
];
