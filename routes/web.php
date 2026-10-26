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
Route::post('/account/stats/logout', [WebStatsController::class, 'logout'])->middleware('throttle:20,1');
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

// Serve these on app.zostream.in without redirects. Keep existing content links.
Route::get('/.well-known/apple-app-site-association', fn () => response()->json([
    'applinks' => ['apps' => [], 'details' => array_map(fn ($id) => ['appID' => trim($id),
        'paths' => ['/watch/*', '/listen/*', '/invite/*']], array_values(config('chat.ios_app_ids')))],
]));
Route::get('/.well-known/assetlinks.json', fn () => response()->json(config('chat.android_fingerprints') ? [[
    'relation' => ['delegate_permission/common.handle_all_urls'],
    'target' => ['namespace' => 'android_app', 'package_name' => 'com.buannel.studio.pvt.ltd.zostream',
        'sha256_cert_fingerprints' => array_values(config('chat.android_fingerprints'))],
]] : []));
// Some template editors encoded the placeholder as literal text before appending
// the token. Canonicalize links already delivered without accepting an invitation.
Route::get('/invite/{token}', function (string $token) {
    if (str_starts_with($token, '{{1}}')) {
        $token = substr($token, 5);
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        return redirect('/invite/'.$token)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
    return response()->view('chat-invitation', ['token' => $token])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
        ->header('X-Robots-Tag', 'noindex, nofollow');
})->where('token', '(?:\{\{1\}\})?[A-Za-z0-9]{1,128}');
