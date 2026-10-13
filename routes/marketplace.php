<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketplaceController::class, 'home'])->name('home');
Route::name('marketplace.')->group(function (): void {
    Route::get('/marketplace', [MarketplaceController::class, 'home'])->name('home');
    Route::get('/products', [MarketplaceController::class, 'index'])->name('products.index');
    Route::get('/search', [MarketplaceController::class, 'index'])->name('search');
    Route::get('/products/{slug}', [MarketplaceController::class, 'show'])->name('products.show');
    Route::get('/categories/{slug}', [MarketplaceController::class, 'category'])->name('categories.show');
    Route::get('/industries/{slug}', [MarketplaceController::class, 'industry'])->name('industries.show');
});
