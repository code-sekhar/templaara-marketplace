<?php

use App\Http\Controllers\Commerce\CartController;
use App\Http\Controllers\Commerce\CheckoutController;
use App\Http\Controllers\Commerce\ExternalPaymentController;
use App\Http\Controllers\Commerce\OrderController;
use App\Http\Controllers\Commerce\PaymentController;
use App\Http\Middleware\DevelopmentPaymentsOnly;
use App\Http\Middleware\PrivatePaymentResponse;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->name('commerce.')->group(function (): void {
    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.store');
    Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::get('checkout/payment/{order}/status', [ExternalPaymentController::class, 'status'])->middleware(PrivatePaymentResponse::class)->name('payments.status');
    Route::post('checkout/payment/{order}/start', [ExternalPaymentController::class, 'start'])->middleware('throttle:10,1')->name('payments.start');
    Route::post('checkout/payment/{order}/razorpay', [ExternalPaymentController::class, 'callback'])->middleware('throttle:30,1')->name('payments.callback');
    Route::get('checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:30,1')->name('checkout.quote');
    Route::post('checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('account/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('account/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('account/orders/{order}/retry', [OrderController::class, 'retry'])->name('orders.retry');
    Route::post('account/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('payments/development/{key}/simulate', [PaymentController::class, 'simulate'])->middleware(DevelopmentPaymentsOnly::class)->name('payments.simulate');
});
Route::prefix('admin/orders')->middleware(['auth', 'verified', 'can:orders.view'])->name('admin.orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('{order}', [OrderController::class, 'show'])->name('show');
    Route::post('{order}/cancel', [OrderController::class, 'cancel'])->middleware('can:orders.manage')->name('cancel');
});
Route::post('payments/{provider}/webhook', [PaymentController::class, 'webhook'])->middleware([DevelopmentPaymentsOnly::class, 'throttle:60,1'])->name('commerce.payments.webhook');

foreach (['stripe', 'razorpay'] as $provider) {
    Route::post('webhooks/'.$provider, [ExternalPaymentController::class, 'webhook'])->defaults('provider', $provider)->middleware('throttle:240,1')->name('commerce.webhooks.'.$provider);
}
