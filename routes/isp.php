<?php

use App\Isp\Http\Controllers\Auth\LoginController;
use App\Isp\Http\Controllers\BranchController;
use App\Isp\Http\Controllers\CustomerController;
use App\Isp\Http\Controllers\CustomerImportController;
use App\Isp\Http\Controllers\DashboardController;
use App\Isp\Http\Controllers\MikroTikCustomerImportController;
use App\Isp\Http\Controllers\PackageController;
use App\Isp\Http\Controllers\PaymentController;
use App\Isp\Http\Controllers\RouterController;
use App\Isp\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('isp')->name('isp.')->group(function (): void {
    Route::redirect('/', '/isp/dashboard');

    Route::middleware('guest:isp')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth:isp', 'isp.active'])->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::middleware('isp.admin')->group(function (): void {
            Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
            Route::resource('routers', RouterController::class)->except('show');

            Route::post('/packages/{package}/sync', [PackageController::class, 'sync'])->name('packages.sync');
            Route::resource('packages', PackageController::class)->except('show');

            Route::get('/customers/import', [CustomerImportController::class, 'create'])->name('customers.import.create');
            Route::post('/customers/import', [CustomerImportController::class, 'store'])->name('customers.import.store');
            Route::get('/customers-import-template', [CustomerImportController::class, 'template'])->name('customers.import.template');
            Route::get('/customers/import-mikrotik', [MikroTikCustomerImportController::class, 'create'])->name('customers.import-mikrotik.create');
            Route::post('/customers/import-mikrotik', [MikroTikCustomerImportController::class, 'store'])->name('customers.import-mikrotik.store');

            Route::resource('branches', BranchController::class)->only(['index', 'create', 'store', 'update', 'destroy']);
            Route::resource('users', UserController::class)->except('show');
        });

        Route::post('/customers/sync-all', [CustomerController::class, 'syncAll'])->name('customers.sync-all');
        Route::post('/customers/onboarding/cashfree/complete', [CustomerController::class, 'completeOnboarding'])->name('customers.onboarding.cashfree.complete');
        Route::get('/customers/{customer}/documents/{side}', [CustomerController::class, 'document'])->name('customers.document');
        Route::post('/customers/{customer}/sync', [CustomerController::class, 'sync'])->name('customers.sync');
        Route::post('/customers/{customer}/toggle', [CustomerController::class, 'toggle'])->name('customers.toggle');
        Route::resource('customers', CustomerController::class)->except('show');

        Route::post('/payments/checkout', [PaymentController::class, 'checkout'])->name('payments.checkout');
        Route::post('/payments/cashfree/complete', [PaymentController::class, 'completeCashfree'])->name('payments.cashfree.complete');
        Route::get('/payments/{payment}/invoice', [PaymentController::class, 'invoice'])->name('payments.invoice');
        Route::resource('payments', PaymentController::class)->only(['index', 'store', 'destroy']);
    });
});
