<?php

use App\Http\Controllers\Payouts\PayoutController;
use App\Http\Controllers\Payouts\PayoutMethodController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:author', 'cache.headers:private;no_store'])->prefix('author')->name('author.')->group(function () {
    Route::get('payout-methods', [PayoutMethodController::class, 'index'])->name('payout-methods.index');
    Route::get('payout-methods/create', [PayoutMethodController::class, 'create'])->name('payout-methods.create');
    Route::post('payout-methods', [PayoutMethodController::class, 'store'])->name('payout-methods.store');
    Route::get('payout-methods/{method}/edit', [PayoutMethodController::class, 'edit'])->name('payout-methods.edit');
    Route::put('payout-methods/{method}', [PayoutMethodController::class, 'update'])->name('payout-methods.update');
    Route::post('payout-methods/{method}/disable', [PayoutMethodController::class, 'disable'])->name('payout-methods.disable');
    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::get('payouts/create', [PayoutController::class, 'create'])->name('payouts.create');
    Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.store');
    Route::get('payouts/{payout}', [PayoutController::class, 'show'])->name('payouts.show');
    Route::post('payouts/{payout}/cancel', [PayoutController::class, 'transition'])->defaults('action', 'cancel')->name('payouts.cancel');
});
Route::middleware(['auth', 'verified', 'permission:payouts.view', 'cache.headers:private;no_store'])->prefix('admin/payouts')->name('admin.payouts.')->group(function () {
    Route::get('/', [PayoutController::class, 'index'])->name('index');
    Route::get('{payout}', [PayoutController::class, 'show'])->name('show');
    foreach (['approve', 'process', 'pay', 'fail', 'cancel'] as $action) {
        Route::post('{payout}/'.$action, [PayoutController::class, 'transition'])->defaults('action', $action)->middleware('permission:payouts.manage')->name($action);
    }Route::post('{payout}/instructions', [PayoutController::class, 'instructions'])->middleware('permission:payouts.manage')->name('instructions');
});
