<?php

use App\Http\Controllers\Earnings\CommissionController;
use App\Http\Controllers\Earnings\EarningController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:author', 'cache.headers:private;no_store'])->prefix('author/earnings')->name('author.earnings.')->group(function () {
    Route::get('/', [EarningController::class, 'index'])->name('index');
    Route::get('{earning}', [EarningController::class, 'show'])->name('show');
});
Route::middleware(['auth', 'verified', 'permission:commissions.view', 'cache.headers:private;no_store'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('earnings', [EarningController::class, 'index'])->name('earnings.index');
    Route::get('earnings/{earning}', [EarningController::class, 'show'])->name('earnings.show');
    Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::post('commissions', [CommissionController::class, 'store'])->middleware('permission:commissions.manage')->name('commissions.store');
    Route::post('commissions/{policy}/deactivate', [CommissionController::class, 'deactivate'])->middleware('permission:commissions.manage')->name('commissions.deactivate');
});
