<?php

use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\ProductReviewController;
use App\Http\Controllers\Author\AuthorProductController;
use App\Http\Controllers\ProductFileController;
use App\Http\Controllers\ProductWorkflowController;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/products')->name('admin.products.')->middleware(['auth', 'verified', 'can:manageAll,'.Product::class])->group(function (): void {
    Route::get('/', [AdminProductController::class, 'index'])->middleware('can:manageAll,'.Product::class)->name('index');
    Route::post('{product}/submit', [ProductWorkflowController::class, 'submit'])->middleware('can:submit,product')->name('submit');
    Route::post('{product}/publish', [ProductWorkflowController::class, 'publish'])->middleware('can:publish,product')->name('publish');
    Route::get('create', [AdminProductController::class, 'create'])->middleware('can:createOfficial,'.Product::class)->name('create');
    Route::post('/', [AdminProductController::class, 'store'])->middleware('can:createOfficial,'.Product::class)->name('store');
    Route::get('{product}', [AdminProductController::class, 'show'])->middleware('can:view,product')->name('show');
    Route::get('{product}/edit', [AdminProductController::class, 'edit'])->middleware('can:update,product')->name('edit');
    Route::put('{product}', [AdminProductController::class, 'update'])->middleware('can:update,product')->name('update');
    Route::delete('{product}', [AdminProductController::class, 'destroy'])->middleware('can:delete,product')->name('destroy');
});

Route::prefix('author/products')->name('author.products.')->middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/', [AuthorProductController::class, 'index'])->middleware('can:viewAny,'.Product::class)->name('index');
    Route::post('{product}/submit', [ProductWorkflowController::class, 'submit'])->middleware('can:submit,product')->name('submit');
    Route::get('create', [AuthorProductController::class, 'create'])->middleware('can:create,'.Product::class)->name('create');
    Route::post('/', [AuthorProductController::class, 'store'])->middleware('can:create,'.Product::class)->name('store');
    Route::get('{product}', [AuthorProductController::class, 'show'])->middleware('can:view,product')->name('show');
    Route::get('{product}/edit', [AuthorProductController::class, 'edit'])->middleware('can:update,product')->name('edit');
    Route::put('{product}', [AuthorProductController::class, 'update'])->middleware('can:update,product')->name('update');
    Route::delete('{product}', [AuthorProductController::class, 'destroy'])->middleware('can:delete,product')->name('destroy');
});

Route::prefix('admin/product-reviews')->name('admin.product-reviews.')->middleware(['auth', 'verified', 'can:reviewAny,'.Product::class])->group(function (): void {
    Route::get('/', [ProductReviewController::class, 'index'])->name('index');
    Route::get('{submission}', [ProductReviewController::class, 'show'])->name('show');
    foreach (['start' => 'start_review', 'changes' => 'request_changes', 'reject' => 'reject', 'approve' => 'approve'] as $route => $action) {
        Route::post('{submission}/'.$route, [ProductReviewController::class, 'transition'])->defaults('action', $action)->name($route);
    }
});

Route::prefix('workspace/products/{product}')->name('products.files.')->middleware(['auth', 'verified'])->group(function (): void {
    Route::post('media', [ProductFileController::class, 'mediaStore'])->name('media.store');
    Route::patch('media/{media}', [ProductFileController::class, 'mediaUpdate'])->name('media.update');
    Route::delete('media/{media}', [ProductFileController::class, 'mediaDelete'])->name('media.destroy');
    Route::post('releases', [ProductFileController::class, 'releaseStore'])->name('releases.store');
    Route::patch('releases/{release}', [ProductFileController::class, 'releaseUpdate'])->name('releases.update');
    Route::post('releases/{release}/artifacts', [ProductFileController::class, 'artifactStore'])->name('artifacts.store');
    Route::delete('releases/{release}/artifacts/{artifact}', [ProductFileController::class, 'artifactDelete'])->name('artifacts.destroy');
    Route::get('releases/{release}/artifacts/{artifact}/download', [ProductFileController::class, 'download'])->name('artifacts.download');
});
Route::get('admin/product-reviews/{submission}/artifacts/{artifact}/download', [ProductFileController::class, 'reviewDownload'])
    ->middleware(['auth', 'verified'])->name('admin.product-reviews.artifacts.download');
