<?php

return [
    'media_disk' => env('PRODUCT_MEDIA_DISK', 'public'),
    'artifact_disk' => env('PRODUCT_ARTIFACT_DISK', 'product_private'),
    'cover_max_kb' => (int) env('PRODUCT_COVER_MAX_KB', 5120),
    'screenshot_max_kb' => (int) env('PRODUCT_SCREENSHOT_MAX_KB', 8192),
    'screenshots_max' => 12,
    'package_max_kb' => (int) env('PRODUCT_PACKAGE_MAX_KB', 256000),
    'documentation_max_kb' => (int) env('PRODUCT_DOCUMENTATION_MAX_KB', 25600),
];
