<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NexframeAccessController;
use App\Http\Controllers\NexframeAnalyticsController;
use App\Http\Controllers\WebStatsController;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/isp.php';

Route::get('/network-check', [\App\Http\Controllers\NetworkDiagnosticController::class, 'show'])
    ->middleware('throttle:20,1')->name('network-check');
Route::post('/network-check/{id}/report', [\App\Http\Controllers\NetworkDiagnosticController::class, 'report'])
    ->whereUuid('id')->middleware(['signed:relative', 'throttle:30,1'])->name('network-check.report');

Route::get('/', function () {
    return view('welcome');
});

Route::view('/admin/{path?}', 'welcome')
    ->where('path', '.*')
    ->name('admin.spa');

Route::view('/creator/{path?}', 'welcome')->where('path', '.*')->name('creator.spa');

Route::get('/ppv/{type}/{id}', [\App\Http\Controllers\PublicPpvController::class, 'show'])
    ->where('type', 'movie|parent|season|episode')->where('id', '[A-Za-z0-9_-]+')
    ->middleware('throttle:60,1')->name('ppv.public');

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
// Backwards compatibility for the original WhatsApp template URL. The template
// treated `{{1}}` as literal text and appended the dynamic token after `/open`.
// Keep links already delivered to customers working while the Meta template is
// updated to use a dynamic URL ending in `/advertise/payment/{{1}}`.
Route::get('/advertise/payment/{legacy}{token}', fn (string $legacy, string $token) => redirect('/advertise/payment/'.$token))
    ->where('legacy', '\\{\\{1\\}\\}')
    ->where('token', '[A-Za-z0-9]{48}');
Route::get('/advertise/payment/{legacy}/open{token}', fn (string $legacy, string $token) => redirect('/advertise/payment/'.$token))
    ->where('legacy', '\\{\\{1\\}\\}')
    ->where('token', '[A-Za-z0-9]{48}');
Route::view('/advertise/payment/{token}', 'welcome')->where('token', '[A-Za-z0-9]{48}');
Route::get('/advertise/payment/{token}/open', fn (string $token) => redirect('/advertise/payment/'.$token))
    ->where('token', '[A-Za-z0-9]{48}');
Route::view('/account-delete', 'welcome');
Route::view('/account/stats', 'welcome');
Route::view('/analytics/nexframe', 'welcome')->name('analytics.nexframe');
Route::prefix('analytics/nexframe')->group(function () {
    Route::get('/session', [NexframeAccessController::class, 'status']);
    Route::post('/login', [NexframeAccessController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/logout', [NexframeAccessController::class, 'logout']);
    Route::get('/data', [NexframeAnalyticsController::class, 'overview'])->middleware('nexframe.access');
});
Route::get('/account/stats/bridge', [WebStatsController::class, 'consumeAppTicket'])->middleware('throttle:20,1');
Route::get('/account/stats/data', [WebStatsController::class, 'data'])->middleware('throttle:60,1');
foreach (['overview', 'watching', 'account', 'payments'] as $section) {
    Route::get('/account/stats/'.$section, [\App\Http\Controllers\CustomerDashboardController::class, $section])->middleware('throttle:60,1');
}
Route::get('/account/stats/payments/export', [\App\Http\Controllers\CustomerDashboardController::class, 'export'])->middleware('throttle:10,1');
Route::get('/account/stats/payments/{payment}/receipt', [\App\Http\Controllers\CustomerDashboardController::class, 'receipt'])->whereNumber('payment')->middleware('throttle:20,1');
Route::post('/account/stats/logout', [WebStatsController::class, 'logout'])->middleware('throttle:20,1');
Route::redirect('/legal/advertising-terms', '/advertising-terms', 301);
Route::view('/legal/{slug}', 'welcome')->where('slug', '[a-z0-9-]+');

Route::get('/check', function () {
    return view('Test');
});

Route::get('/ads/{ad}', [AdsController::class, 'show'])->name('ads.show');

Route::get('/invoices/bandwidth/{bandwidthInvoice}', [\App\Isp\Http\Controllers\PublicBandwidthInvoiceController::class, 'show'])
    ->middleware('signed')->name('invoice.bandwidth.show');
Route::get('/invoices/bandwidth/{bandwidthInvoice}/pdf', [\App\Isp\Http\Controllers\PublicBandwidthInvoiceController::class, 'pdf'])
    ->middleware('signed')->name('invoice.bandwidth.pdf');

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

// Serve these on app.zostream.in without redirects. Keep existing content links.
Route::get('/.well-known/apple-app-site-association', fn () => response()->json([
    'applinks' => ['apps' => [], 'details' => array_map(fn ($id) => ['appID' => trim($id),
        'paths' => ['/watch/*', '/listen/*']], array_values(config('services.app_links.ios_app_ids')))],
]));
Route::get('/.well-known/assetlinks.json', fn () => response()->json(config('services.app_links.android_fingerprints') ? [[
    'relation' => ['delegate_permission/common.handle_all_urls'],
    'target' => ['namespace' => 'android_app', 'package_name' => 'com.buannel.studio.pvt.ltd.zostream',
        'sha256_cert_fingerprints' => array_values(config('services.app_links.android_fingerprints'))],
]] : []));
