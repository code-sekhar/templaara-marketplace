<?php

use App\Http\Controllers\Support\SupportController;
use Illuminate\Support\Facades\Route;

foreach (['account', 'author', 'admin'] as $space) {
    Route::middleware(['auth', 'cache.headers:private;no_store', ...($space === 'admin' ? ['permission:support.view'] : ($space === 'author' ? ['role:author', 'permission:products.view'] : []))])->prefix($space.'/support')->name($space.'.support.')->group(function () use ($space) {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        if ($space === 'account') {
            Route::get('create', [SupportController::class, 'create'])->name('create');
            Route::post('/', [SupportController::class, 'store'])->middleware('throttle:support-write')->name('store');
        }Route::get('attachments/{attachment}', [SupportController::class, 'download'])->middleware('throttle:support-download')->name('attachments.download');
        Route::get('{ticket}', [SupportController::class, 'show'])->name('show');
        Route::middleware(['throttle:support-write', ...($space === 'admin' ? ['permission:support.manage'] : [])])->group(function () use ($space) {
            Route::post('{ticket}/messages', [SupportController::class, 'message'])->name('messages');
            if ($space === 'admin') {
                Route::post('{ticket}/notes', [SupportController::class, 'message'])->defaults('action', 'note')->name('notes');
            }foreach (match ($space) {
                'account' => ['close', 'reopen'],'author' => ['resolve', 'escalate'],'admin' => ['resolve', 'close', 'reopen', 'escalate', 'deescalate', 'priority']
            } as $a) {
                Route::post('{ticket}/'.$a, [SupportController::class, 'action'])->defaults('action', $a)->name($a);
            }
        });
    });
}
