<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:reports.view', 'cache.headers:private;no_store'])->prefix('admin')->name('admin.analytics.')->group(function () {
    Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->name('dashboard');
    Route::get('reports', [AnalyticsController::class, 'index'])->name('index');
    Route::get('reports/{report}/export', [AnalyticsController::class, 'export'])->name('export');
    Route::get('reports/{report}', [AnalyticsController::class, 'show'])->name('show');
});
