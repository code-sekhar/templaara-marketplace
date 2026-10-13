<?php

use App\Http\Controllers\Refunds\RefundController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'cache.headers:private;no_store'])->group(function () {
    Route::get('account/refunds', [RefundController::class, 'index'])->name('account.refunds.index');
    Route::get('account/refunds/{refund}', [RefundController::class, 'show'])->name('account.refunds.show');
    Route::post('account/refunds/{refund}/cancel', [RefundController::class, 'transition'])->defaults('action', 'cancel')->name('account.refunds.cancel');
    Route::get('account/orders/{order}/refunds', [RefundController::class, 'create'])->name('account.refunds.create');
    Route::post('account/orders/{order}/refunds', [RefundController::class, 'store'])->name('account.refunds.store');
});
Route::middleware(['auth', 'verified', 'permission:refunds.view', 'cache.headers:private;no_store'])->prefix('admin/refunds')->name('admin.refunds.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('orders/{order}/create', [RefundController::class, 'create'])->middleware('permission:refunds.manage')->name('create');
    Route::post('orders/{order}', [RefundController::class, 'store'])->middleware('permission:refunds.manage')->name('store');
    Route::get('{refund}', [RefundController::class, 'show'])->name('show');
    foreach (['approve', 'process', 'complete', 'fail', 'cancel'] as $a) {
        Route::post('{refund}/'.$a, [RefundController::class, 'transition'])->defaults('action', $a)->middleware('permission:refunds.manage')->name($a);
    }
});
