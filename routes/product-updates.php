<?php

use App\Http\Controllers\ProductUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('admin/product-updates', [ProductUpdateController::class, 'queue'])->name('admin.product-updates.index');
    Route::post('workspace/products/{product}/updates', [ProductUpdateController::class, 'create'])->name('products.updates.create');
    Route::prefix('workspace/products/{product}/updates/{revision}')->name('products.updates.')->group(function () {
        Route::get('/', [ProductUpdateController::class, 'show'])->name('show');
        Route::put('/', [ProductUpdateController::class, 'save'])->name('save');
        Route::patch('release', [ProductUpdateController::class, 'release'])->name('release');
        Route::post('files', [ProductUpdateController::class, 'upload'])->name('upload');
        Route::delete('media/{media}', [ProductUpdateController::class, 'remove'])->name('remove');
        foreach (['submit' => 'submit', 'start' => 'start_review', 'changes' => 'request_changes', 'reject' => 'reject', 'approve' => 'approve', 'publish' => 'publish'] as $path => $action) {
            Route::post($path, [ProductUpdateController::class, 'transition'])->defaults('action', $action)->name($path);
        }
    });
});
