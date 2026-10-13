<?php

use App\Http\Controllers\Reviews\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'cache.headers:private;no_store'])->prefix('account/reviews')->name('account.reviews.')->group(function () {
    Route::get('/', [ReviewController::class, 'index'])->name('index');
    Route::get('products/{product:slug}', [ReviewController::class, 'create'])->name('create');
    Route::post('products/{product:slug}', [ReviewController::class, 'store'])->middleware('throttle:review-write')->name('store');
    Route::get('{review}', [ReviewController::class, 'show'])->name('show');
    Route::put('{review}', [ReviewController::class, 'action'])->defaults('action', 'edit')->middleware('throttle:review-write')->name('update');
    foreach (['withdraw', 'restore'] as $a) {
        Route::post('{review}/'.$a, [ReviewController::class, 'action'])->defaults('action', $a)->middleware('throttle:review-write')->name($a);
    }
});
foreach (['author', 'admin'] as $space) {
    Route::middleware(['auth', $space === 'author' ? 'role:author' : 'permission:reviews.view', 'cache.headers:private;no_store'])->prefix($space.'/reviews')->name($space.'.reviews.')->group(function () use ($space) {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('{review}', [ReviewController::class, 'show'])->name('show');
        foreach (['respond', 'edit_response', ...($space === 'admin' ? ['hide', 'unhide'] : [])] as $a) {
            Route::post('{review}/'.$a, [ReviewController::class, 'action'])->defaults('action', $a)->middleware(['throttle:review-write', ...($space === 'admin' ? ['permission:reviews.manage'] : [])])->name($a);
        }
    });
}
