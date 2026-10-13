<?php

return [
    'gateway' => env('COMMERCE_GATEWAY', 'disabled'),
    'development_enabled' => (bool) env('COMMERCE_DEVELOPMENT_ENABLED', false),
    'cart_limit' => 50,
    'providers' => [
        'INR' => env('COMMERCE_PROVIDER_INR', 'razorpay'),
        'USD' => env('COMMERCE_PROVIDER_USD', 'stripe'),
        'EUR' => env('COMMERCE_PROVIDER_EUR', 'stripe'),
        'GBP' => env('COMMERCE_PROVIDER_GBP', 'stripe'),
    ],
    'stripe' => [
        'enabled' => (bool) env('STRIPE_ENABLED', false), 'mode' => env('STRIPE_MODE', 'test'),
        'public_key' => env('STRIPE_PUBLIC_KEY'), 'secret_key' => env('STRIPE_SECRET_KEY'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
    'razorpay' => [
        'enabled' => (bool) env('RAZORPAY_ENABLED', false), 'mode' => env('RAZORPAY_MODE', 'test'),
        'key_id' => env('RAZORPAY_KEY_ID'), 'key_secret' => env('RAZORPAY_KEY_SECRET'), 'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
