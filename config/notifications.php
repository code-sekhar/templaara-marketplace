<?php

return [
    // A dedicated asynchronous connection must never be sync/deferred/background.
    'connection' => env('NOTIFICATION_QUEUE_CONNECTION', 'database'),
    'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
    'started_at' => null,
];
