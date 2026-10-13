<?php

use App\Http\Controllers\Account\LibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('account/library')->name('library.')->group(function (): void {
    Route::get('/', [LibraryController::class, 'index'])->name('index');
    Route::get('{entitlement}', [LibraryController::class, 'show'])->whereUuid('entitlement')->name('show');
    Route::post('{entitlement}/activations/{activation}/deactivate', [LibraryController::class, 'deactivate'])->whereUuid('entitlement')->whereUuid('activation')->name('activations.deactivate');
    Route::get('{entitlement}/download', [LibraryController::class, 'download'])->whereUuid('entitlement')->middleware('throttle:30,1')->name('download');
});
Route::get('admin/entitlements', [LibraryController::class, 'index'])->middleware(['auth', 'verified', 'can:orders.view'])->name('admin.entitlements.index');
