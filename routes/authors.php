<?php

use App\Http\Controllers\Admin\AuthorApplicationController;
use App\Http\Controllers\Admin\AuthorDirectoryController;
use App\Http\Controllers\Author\AuthorOnboardingController;
use App\Http\Controllers\Author\AuthorPortfolioController;
use App\Http\Controllers\PublicAuthorController;
use App\Http\Middleware\RequireAuthorEmailVerification;
use App\Models\AuthorApplication;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'cache.headers:private;no_store'])->prefix('author')->name('author.')->group(function (): void {
    Route::get('/', [AuthorOnboardingController::class, 'dashboard'])->name('dashboard');
    Route::get('onboarding', [AuthorOnboardingController::class, 'show'])->middleware(RequireAuthorEmailVerification::class)->name('onboarding.show');
    Route::put('onboarding', [AuthorOnboardingController::class, 'save'])->middleware(RequireAuthorEmailVerification::class)->name('onboarding.save');
    Route::post('onboarding/submit', [AuthorOnboardingController::class, 'submit'])->middleware(RequireAuthorEmailVerification::class)->name('onboarding.submit');
    Route::get('profile', [AuthorOnboardingController::class, 'show'])->middleware('role:author')->name('profile.show');
    Route::put('profile', [AuthorOnboardingController::class, 'save'])->middleware(['role:author', 'throttle:30,1'])->name('profile.save');
});
Route::middleware(['auth', 'verified', 'can:viewAny,'.AuthorApplication::class, RequireAuthorEmailVerification::class])->prefix('admin/author-applications')->name('admin.author-applications.')->group(function (): void {
    Route::get('/', [AuthorApplicationController::class, 'index'])->name('index');
    Route::get('{application}', [AuthorApplicationController::class, 'show'])->name('show');
    foreach (['start', 'changes', 'reject', 'approve'] as $action) {
        Route::post('{application}/'.$action, [AuthorApplicationController::class, 'transition'])->defaults('action', $action)->middleware('can:review,application')->name($action);
    }
});

Route::get('/authors/{slug}', [PublicAuthorController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('marketplace.authors.show');
Route::middleware(['auth', 'verified', RequireAuthorEmailVerification::class, 'throttle:30,1'])->prefix('author')->name('author.')->group(function (): void {
    Route::post('portfolio', [AuthorPortfolioController::class, 'store'])->name('portfolio.store');
    Route::post('portfolio/reorder', [AuthorPortfolioController::class, 'reorder'])->name('portfolio.reorder');
    Route::put('portfolio/{item}', [AuthorPortfolioController::class, 'update'])->name('portfolio.update');
    Route::delete('portfolio/{item}', [AuthorPortfolioController::class, 'destroy'])->name('portfolio.destroy');
    Route::post('profile/media', [AuthorPortfolioController::class, 'media'])->name('profile.media');
    Route::post('portfolio/{item}/media', [AuthorPortfolioController::class, 'media'])->name('portfolio.media');
});
Route::get('/admin/authors', [AuthorDirectoryController::class, 'index'])->middleware(['auth', 'verified', 'permission:authors.view'])->name('admin.authors.index');
