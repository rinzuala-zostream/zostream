<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/admin/{path?}', 'welcome')
    ->where('path', '.*')
    ->name('admin.spa');

Route::view('/about-us', 'welcome');
Route::view('/contact-us', 'welcome');
Route::view('/download', 'welcome');
Route::view('/terms-and-conditions', 'welcome');
Route::view('/privacy-policy', 'welcome');
Route::view('/refund-and-cancellation', 'welcome');
Route::view('/return-policy', 'welcome');
Route::view('/shipping-policy', 'welcome');
Route::view('/copyright-policy', 'welcome');
Route::view('/advertising-terms', 'welcome');
Route::view('/faq', 'welcome');
Route::view('/advertise', 'welcome');
Route::view('/advertise/status/{token}', 'welcome')->where('token', '[A-Za-z0-9]{48}');
Route::view('/account-delete', 'welcome');
Route::redirect('/legal/advertising-terms', '/advertising-terms', 301);
Route::view('/legal/{slug}', 'welcome')->where('slug', '[a-z0-9-]+');

Route::get('/check', function () {
    return view('Test');
});

Route::get('/ads/{ad}', [AdsController::class, 'show'])->name('ads.show');

Route::get('/invoices/payments/{payment}', [InvoiceController::class, 'show'])
    ->middleware('signed')
    ->name('invoice.payments.show');

Route::get('/invoices/payments/{payment}/pdf', [InvoiceController::class, 'pdf'])
    ->middleware('signed')
    ->name('invoice.payments.pdf');

Route::get('/redis-test', function () {
    Redis::set('mykey', 'Hello Redis!');

    return Redis::get('mykey'); // Should return "Hello Redis!"
});

Route::get('/firebase-test', function () {

    $firebase = (new \Kreait\Firebase\Factory)
        ->withServiceAccount(storage_path('app/firebase/zostream.json'))
        ->withDatabaseUri(env('FIREBASE_DATABASE_URL'));

    $database = $firebase->createDatabase();

    $database->getReference('test')->set([
        'status' => 'connected',
    ]);

    return 'Firebase connected';
});

Route::get('/upload-test', function () {
    return view('upload');
});

Route::get('/test-r2', function () {
    Storage::disk('r2')->put('test.txt', 'hello world');

    return 'OK';
});
