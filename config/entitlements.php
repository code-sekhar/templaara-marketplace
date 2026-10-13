<?php

return [
    'temporary_urls' => (bool) env('ENTITLEMENT_TEMPORARY_URLS', false),
    'url_minutes' => max(1, min(10, (int) env('ENTITLEMENT_URL_MINUTES', 5))),
];
