<?php

use App\Http\Controllers\Admin\MarketplaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'cache.headers:private;no_store'])->prefix('admin/settings')->name('admin.marketplace-settings.')->group(function (): void {
    Route::get('/', [MarketplaceSettingsController::class, 'index'])->middleware('permission:settings.view')->name('index');
    Route::put('/{group}', [MarketplaceSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('update');
});
