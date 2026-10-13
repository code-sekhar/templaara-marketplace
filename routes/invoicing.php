<?php

use App\Http\Controllers\Invoicing\InvoiceController;
use App\Http\Middleware\PrivatePaymentResponse;
use Illuminate\Support\Facades\Route;

foreach (['account' => ['auth'], 'admin' => ['auth', 'verified', 'can:orders.view']] as $prefix => $middleware) {
    Route::prefix($prefix.'/invoices')->middleware([...$middleware, PrivatePaymentResponse::class])->name($prefix.'.invoices.')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('{invoice:public_uuid}/pdf', [InvoiceController::class, 'pdf'])->whereUuid('invoice')->middleware('throttle:30,1')->name('pdf');
        Route::get('{invoice:public_uuid}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('show');
    });
}
