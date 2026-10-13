<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\IndustryController;
use App\Http\Controllers\Admin\TechnologyController;
use App\Http\Controllers\PublicCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->name('catalog.')->group(function (): void {
    Route::get('categories', [PublicCatalogController::class, 'categories'])->name('categories');
    Route::get('industries', [PublicCatalogController::class, 'industries'])->name('industries');
    Route::get('technologies', [PublicCatalogController::class, 'technologies'])->name('technologies');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified'])->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->middleware('can:categories.view')->name('categories.index');
    Route::get('categories/create', [CategoryController::class, 'create'])->middleware('can:categories.create')->name('categories.create');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('can:categories.create')->name('categories.store');
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->middleware('can:categories.update')->name('categories.edit');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('can:categories.update')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('can:categories.delete')->name('categories.destroy');
    Route::get('industries', [IndustryController::class, 'index'])->middleware('can:industries.view')->name('industries.index');
    Route::get('industries/create', [IndustryController::class, 'create'])->middleware('can:industries.create')->name('industries.create');
    Route::post('industries', [IndustryController::class, 'store'])->middleware('can:industries.create')->name('industries.store');
    Route::get('industries/{industry}/edit', [IndustryController::class, 'edit'])->middleware('can:industries.update')->name('industries.edit');
    Route::put('industries/{industry}', [IndustryController::class, 'update'])->middleware('can:industries.update')->name('industries.update');
    Route::delete('industries/{industry}', [IndustryController::class, 'destroy'])->middleware('can:industries.delete')->name('industries.destroy');
    Route::get('technologies', [TechnologyController::class, 'index'])->middleware('can:technologies.view')->name('technologies.index');
    Route::get('technologies/create', [TechnologyController::class, 'create'])->middleware('can:technologies.create')->name('technologies.create');
    Route::post('technologies', [TechnologyController::class, 'store'])->middleware('can:technologies.create')->name('technologies.store');
    Route::get('technologies/{technology}/edit', [TechnologyController::class, 'edit'])->middleware('can:technologies.update')->name('technologies.edit');
    Route::put('technologies/{technology}', [TechnologyController::class, 'update'])->middleware('can:technologies.update')->name('technologies.update');
    Route::delete('technologies/{technology}', [TechnologyController::class, 'destroy'])->middleware('can:technologies.delete')->name('technologies.destroy');
});
