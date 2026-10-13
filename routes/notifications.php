<?php

use App\Http\Controllers\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'cache.headers:private;no_store'])->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:60,1')->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->middleware('throttle:60,1')->name('notifications.read');
    Route::get('/settings/notifications', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::put('/settings/notifications', [NotificationController::class, 'updatePreferences'])->middleware('throttle:30,1')->name('notifications.preferences.update');
});
