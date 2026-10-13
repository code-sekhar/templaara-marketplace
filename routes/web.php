<?php

use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/marketplace.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', CustomerDashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';

require __DIR__.'/catalog.php';

require __DIR__.'/products.php';

require __DIR__.'/commerce.php';

require __DIR__.'/library.php';

require __DIR__.'/authors.php';

require __DIR__.'/product-updates.php';

require __DIR__.'/earnings.php';

require __DIR__.'/payouts.php';

require __DIR__.'/refunds.php';

require __DIR__.'/reviews.php';

require __DIR__.'/support.php';

require __DIR__.'/notifications.php';

require __DIR__.'/analytics.php';
require __DIR__.'/marketplace-settings.php';

Route::get('/health/ready', ReadinessController::class)->middleware('throttle:60,1')->name('health.ready');

require __DIR__.'/invoicing.php';
