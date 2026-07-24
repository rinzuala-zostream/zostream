<?php

use App\Http\Controllers\Api\V4\AccountController;
use App\Http\Controllers\Api\V4\AuthController;
use App\Http\Controllers\Api\V4\BillingController;
use App\Http\Controllers\Api\V4\CatalogController;
use App\Http\Controllers\Api\V4\ChannelSubscriptionController;
use App\Http\Controllers\Api\V4\LibraryController;
use App\Http\Controllers\Api\V4\OfflineController;
use App\Http\Controllers\Api\V4\PlaybackController;
use App\Http\Controllers\Api\V4\QrSessionController as V4QrSessionController;
use App\Http\Controllers\Api\V4\SupportController;
use App\Http\Controllers\Channel\ChannelController;
use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\New\AdminWhatsAppController;
use App\Http\Controllers\New\AppUpdateController;
use App\Http\Controllers\New\BannerController;
use App\Http\Controllers\New\CustomerSupportController;
use App\Http\Controllers\New\DashboardController;
use App\Http\Controllers\New\DeviceController;
use App\Http\Controllers\New\EpisodeController;
use App\Http\Controllers\New\PaymentController;
use App\Http\Controllers\New\PaymentHistoryController;
use App\Http\Controllers\New\PlanController;
use App\Http\Controllers\New\PollController;
use App\Http\Controllers\New\SeasonController;
use App\Http\Controllers\New\SubscriptionController;
use App\Http\Controllers\New\UserController;
use App\Support\Api\V4Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Zo Stream API v4
|--------------------------------------------------------------------------
|
| This is the canonical contract for every actively maintained client.
| Public, customer, administrator and webhook boundaries are intentionally
| separate. Legacy and v3 routes remain outside this file only during the
| migration window.
|
*/

Route::prefix('v4')
    ->middleware(['api.client', 'api.v4'])
    ->group(function () {
        Route::get('/system/health', fn () => V4Response::success([
            'service' => 'zostream-api',
            'status' => 'available',
        ]))->name('v4.system.health');

        Route::prefix('auth')->group(function () {
            Route::post('/otp/request', [AuthController::class, 'requestOtp'])
                ->middleware('throttle:6,1');
            Route::post('/admin/otp/request', [AdminWhatsAppController::class, 'requestOtp'])
                ->middleware('throttle:6,1');
            Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])
                ->middleware('throttle:10,1');
            Route::post('/tokens/refresh', [AuthController::class, 'refresh'])
                ->middleware('throttle:30,1');
        });

        Route::prefix('app-releases')->group(function () {
            Route::get('/', [AppUpdateController::class, 'index']);
            Route::get('/{platform}', [AppUpdateController::class, 'show']);
        });

        Route::prefix('catalog')->group(function () {
            Route::get('/home', [CatalogController::class, 'home']);
            Route::get('/items', [CatalogController::class, 'index']);
            Route::get('/items/search', [CatalogController::class, 'search']);
            Route::get('/items/filter', [CatalogController::class, 'filter']);
            Route::get('/genres', [CatalogController::class, 'genres']);
            Route::get('/latest', [CatalogController::class, 'latest']);
            Route::get('/ppv', [CatalogController::class, 'ppv']);
            Route::get('/items/{contentId}', [CatalogController::class, 'show']);
        });

        Route::get('/banners', [BannerController::class, 'index']);
        Route::get('/banners/{id}', [BannerController::class, 'show']);

        Route::get('/catalog/items/{movieId}/seasons', [SeasonController::class, 'index']);
        Route::get('/catalog/seasons/{id}', [SeasonController::class, 'show']);
        Route::get('/catalog/seasons/{seasonId}/episodes', [EpisodeController::class, 'index']);
        Route::get('/catalog/episodes/{id}', [EpisodeController::class, 'show']);

        Route::get('/billing/plans/device/{deviceType}', [SubscriptionController::class, 'getByDeviceType']);
        Route::post('/webhooks/razorpay', [PaymentController::class, 'razorpayWebhook']);

        Route::get('/channels', [ChannelController::class, 'index']);
        Route::get('/channels/{channelId}', [ChannelController::class, 'show']);
        Route::get('/channels/{channelId}/plans', [ChannelController::class, 'plans']);
        Route::get('/channels/{channelId}/contents', [ChannelController::class, 'contents']);

        // A TV creates and polls a login session before it has an access token.
        // Possession of the high-entropy QR token authorizes these two operations.
        Route::post('/qr-sessions', [V4QrSessionController::class, 'create'])
            ->middleware('throttle:20,1');
        Route::get('/qr-sessions/{token}/status', [V4QrSessionController::class, 'status'])
            ->middleware('throttle:120,1');

        Route::middleware('auth.token')->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);

            Route::prefix('account')->group(function () {
                Route::get('/', [AccountController::class, 'show']);
                Route::patch('/', [AccountController::class, 'update']);
                Route::delete('/', [AccountController::class, 'destroy']);
                Route::get('/devices', [AccountController::class, 'devices']);
                Route::post('/devices', [AccountController::class, 'storeDevice']);
                Route::delete('/devices', [AccountController::class, 'clearDevices']);
                Route::get('/subscriptions', [AccountController::class, 'subscriptions']);
                Route::get('/phone/status', [AccountController::class, 'phoneStatus']);
                Route::put('/phone', [AccountController::class, 'updatePhone']);
            });

            Route::prefix('catalog')->group(function () {
                Route::get('/items/{contentId}/details', [CatalogController::class, 'details']);
                Route::get('/items/{contentId}/recommendations', [CatalogController::class, 'recommendations']);
                Route::get('/items/{contentId}/ppv-status', [CatalogController::class, 'ppvStatus']);
            });

            Route::prefix('library')->group(function () {
                Route::get('/wishlist', [LibraryController::class, 'wishlist']);
                Route::post('/wishlist', [LibraryController::class, 'addToWishlist']);
                Route::get('/wishlist/{contentId}', [LibraryController::class, 'wishlistStatus']);
                Route::delete('/wishlist/{contentId}', [LibraryController::class, 'removeFromWishlist']);
                Route::get('/history', [LibraryController::class, 'history']);
                Route::put('/progress', [LibraryController::class, 'saveProgress']);
            });

            Route::prefix('playback/sessions')->group(function () {
                Route::post('/', [PlaybackController::class, 'start'])
                    ->middleware('throttle:60,1');
                Route::post('/heartbeat', [PlaybackController::class, 'heartbeat'])
                    ->middleware('throttle:300,1');
                Route::post('/stop', [PlaybackController::class, 'stop'])
                    ->middleware('throttle:120,1');
            });

            Route::prefix('billing')->group(function () {
                Route::post('/subscriptions', [BillingController::class, 'createSubscription']);
                Route::post('/subscriptions/with-payment', [BillingController::class, 'createSubscriptionWithPayment']);
                Route::post('/payments/process', [BillingController::class, 'processPayments']);
                Route::post('/payments/razorpay/orders', [BillingController::class, 'createRazorpayOrder']);
                Route::post('/payments/razorpay/verify', [BillingController::class, 'verifyRazorpayPayment']);
                Route::post('/payments/apple/subscriptions', [BillingController::class, 'processAppleSubscription']);
            });

            Route::prefix('qr-sessions')->group(function () {
                Route::get('/{token}', [V4QrSessionController::class, 'inspect']);
                Route::post('/{token}/verify', [V4QrSessionController::class, 'verify']);
                Route::post('/{token}/selection', [V4QrSessionController::class, 'updateSelection']);
                Route::post('/{token}/subscription-payment', [V4QrSessionController::class, 'startSubscriptionPayment']);
                Route::post('/{token}/subscription-payment/complete', [V4QrSessionController::class, 'completeSubscriptionPayment']);
            });

            Route::get('/offline/access', [OfflineController::class, 'requestAccess']);

            Route::post('/channels/{channelId}/subscriptions', [ChannelSubscriptionController::class, 'store']);
            Route::delete('/channels/{channelId}/subscriptions', [ChannelSubscriptionController::class, 'destroy']);

            Route::prefix('support')->group(function () {
                Route::get('/tickets', [SupportController::class, 'index']);
                Route::post('/tickets', [SupportController::class, 'store']);
                Route::get('/tickets/{id}', [SupportController::class, 'show']);
                Route::post('/tickets/{id}/replies', [SupportController::class, 'reply']);
            });

            Route::middleware('admin.token')
                ->prefix('admin')
                ->group(function () {
                    Route::get('/dashboard', [DashboardController::class, 'index']);

                    Route::get('/users/find', [UserController::class, 'find']);
                    Route::get('/users-search', [UserController::class, 'search']);
                    Route::apiResource('users', UserController::class);

                    Route::get('/devices', [DeviceController::class, 'index']);
                    Route::get('/devices/search', [DeviceController::class, 'search']);
                    Route::get('/devices/user/{userId}', [DeviceController::class, 'getByUser']);
                    Route::post('/devices/clear', [DeviceController::class, 'clear']);
                    Route::apiResource('devices', DeviceController::class)->except(['index']);

                    Route::post('/catalog/items', [\App\Http\Controllers\New\MovieController::class, 'store']);
                    Route::put('/catalog/items/{id}', [\App\Http\Controllers\New\MovieController::class, 'update']);
                    Route::delete('/catalog/items/{id}', [\App\Http\Controllers\New\MovieController::class, 'destroy']);
                    Route::get('/catalog/items/{id}/links', [\App\Http\Controllers\New\MovieController::class, 'adminGetLink']);

                    Route::post('/catalog/seasons', [SeasonController::class, 'store']);
                    Route::get('/catalog/seasons/search', [SeasonController::class, 'searchByMovieTitle']);
                    Route::put('/catalog/seasons/{id}', [SeasonController::class, 'update']);
                    Route::delete('/catalog/seasons/{id}', [SeasonController::class, 'destroy']);

                    Route::post('/catalog/episodes', [EpisodeController::class, 'store']);
                    Route::put('/catalog/episodes/{id}', [EpisodeController::class, 'update']);
                    Route::delete('/catalog/episodes/{id}', [EpisodeController::class, 'destroy']);
                    Route::get('/catalog/episodes/{episodeId}/urls', [\App\Http\Controllers\New\MovieController::class, 'getUrls']);
                    Route::post('/catalog/episode-urls', [EpisodeController::class, 'addUrl']);
                    Route::put('/catalog/episode-urls/{id}', [EpisodeController::class, 'updateUrl']);
                    Route::delete('/catalog/episode-urls/{id}', [EpisodeController::class, 'deleteUrl']);

                    Route::post('/banners', [BannerController::class, 'store']);
                    Route::put('/banners/{id}', [BannerController::class, 'update']);
                    Route::delete('/banners/{id}', [BannerController::class, 'destroy']);

                    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
                    Route::get('/subscriptions/search', [SubscriptionController::class, 'searchSubscribers']);
                    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
                    Route::post('/subscriptions/with-payment', [SubscriptionController::class, 'createSubscriptionWithPayment']);
                    Route::get('/subscriptions/user/{userId}', [SubscriptionController::class, 'getByUser']);
                    Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show']);
                    Route::put('/subscriptions/{id}', [SubscriptionController::class, 'update']);
                    Route::delete('/subscriptions/{id}', [SubscriptionController::class, 'destroy']);

                    Route::apiResource('plans', PlanController::class);
                    Route::get('/plan-features', [PlanController::class, 'featureIndex']);
                    Route::post('/plan-features', [PlanController::class, 'storeFeature']);
                    Route::get('/plan-features/{featureId}', [PlanController::class, 'showFeature']);
                    Route::put('/plan-features/{featureId}', [PlanController::class, 'updateFeature']);
                    Route::delete('/plan-features/{featureId}', [PlanController::class, 'destroyFeature']);
                    Route::get('/plans/{planId}/features', [PlanController::class, 'planFeatures']);
                    Route::post('/plans/{planId}/features', [PlanController::class, 'storePlanFeature']);

                    Route::get('/polls', [PollController::class, 'index']);
                    Route::post('/polls', [PollController::class, 'store']);
                    Route::get('/polls/{id}', [PollController::class, 'show']);
                    Route::put('/polls/{id}', [PollController::class, 'update']);
                    Route::delete('/polls/{id}', [PollController::class, 'destroy']);
                    Route::get('/polls/{id}/results', [PollController::class, 'results']);
                    Route::get('/polls/{id}/voters', [PollController::class, 'voters']);
                    Route::post('/polls/{id}/options', [PollController::class, 'storeOption']);
                    Route::put('/poll-options/{optionId}', [PollController::class, 'updateOption']);
                    Route::delete('/poll-options/{optionId}', [PollController::class, 'destroyOption']);

                    Route::put('/app-releases/{platform}', [AppUpdateController::class, 'update']);
                    Route::post('/notifications/push', [FCMNotificationController::class, 'send']);
                    Route::post('/whatsapp/send', [AdminWhatsAppController::class, 'send']);

                    Route::get('/payments/user/{userId}', [PaymentHistoryController::class, 'getByUser']);

                    Route::post('/qr-sessions', [V4QrSessionController::class, 'createAdmin']);

                    Route::post('/channels', [ChannelController::class, 'store']);
                    Route::put('/channels/{channelId}', [ChannelController::class, 'update']);
                    Route::delete('/channels/{channelId}', [ChannelController::class, 'destroy']);
                    Route::post('/channels/{channelId}/plans', [ChannelController::class, 'storePlan']);
                    Route::put('/channel-plans/{planId}', [ChannelController::class, 'updatePlan']);
                    Route::delete('/channel-plans/{planId}', [ChannelController::class, 'destroyPlan']);
                    Route::post('/channels/{channelId}/contents', [ChannelController::class, 'storeContent']);
                    Route::put('/channel-contents/{contentId}', [ChannelController::class, 'updateContent']);
                    Route::delete('/channel-contents/{contentId}', [ChannelController::class, 'destroyContent']);

                    Route::put('/support/tickets/{id}/status', [CustomerSupportController::class, 'updateStatus']);
                    Route::get('/support/tickets', [CustomerSupportController::class, 'index']);
                    Route::get('/support/tickets/{id}', [CustomerSupportController::class, 'show']);
                    Route::post('/support/tickets/{id}/replies', [CustomerSupportController::class, 'reply']);
                    Route::post('/support/devices', [CustomerSupportController::class, 'registerAdminDevice']);
                    Route::delete('/support/devices', [CustomerSupportController::class, 'deleteAdminDevice']);
                });
        });
    });
