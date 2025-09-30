<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\AlsoLikeController;
use App\Http\Controllers\AxinomLicense;
use App\Http\Controllers\CashFreeController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\CalculatePlan;
use App\Http\Controllers\CheckDeviceAvailable;
use App\Http\Controllers\DetailsController;
use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\HlsFolderController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieSearchController;
use App\Http\Controllers\PaymentStatusController;
use App\Http\Controllers\PhonePeSdkV2Controller;
use App\Http\Controllers\PlanListController;
use App\Http\Controllers\PlanPriceController;
use App\Http\Controllers\PPVPriceCalculate;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RequestOTPController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TempPayment;
use App\Http\Controllers\UpdateUserDevice;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerifyOTPController;
use App\Http\Controllers\WatchPositionController;
use App\Http\Controllers\ZonetController;
use App\Http\Controllers\ZonetOperatorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeviceManagementController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [RegisterController::class, 'store']);

Route::prefix('device')->group(function () {

    Route::get('/store', [DeviceManagementController::class, 'store']);
    Route::get('/delete', [DeviceManagementController::class, 'delete']);
    Route::get('/get', [DeviceManagementController::class, 'get']);
    Route::put('/update', [DeviceManagementController::class, 'update']);
    Route::get('/available', [CheckDeviceAvailable::class, 'checkDeviceAvailability']);
});

Route::get('/test', function () {
    return response()->json(['message' => Crypt::encryptString(config('app.api_key'))]);
});

Route::get('/user', [UserController::class, 'getUserData']);
Route::get('/birthday-send', [UserController::class, 'sendWishes']);

Route::get('/get-subscription', [SubscriptionController::class, 'getSubscription']);
Route::get('/add-subscription', [SubscriptionController::class, 'addSubscription']);
Route::get('/subscription-history', [SubscriptionController::class, 'getHistory']);

Route::get('/movies', [MovieController::class, 'getMovies']);
Route::put('/movies/{id}', [MovieController::class, 'update']);
Route::delete('/movies/{id}', [MovieController::class, 'destroy']);
Route::get('/view', [MovieController::class, 'incrementView']);
Route::post('/insert', [MovieController::class, 'insert']);

//update device for new device or change device
Route::get('/device', [UpdateUserDevice::class, 'updateDevice']);

Route::get('/payment-status', [PaymentStatusController::class, 'processUserPayments']);
Route::post('/phonepe/sdk-order', [PhonePeSdkV2Controller::class, 'createSdkOrder']);
Route::get('/phonepe/success/{id}', [PhonePeSdkV2Controller::class, 'success'])->name('phonepe.success');

// Check order status
Route::get('/api/phonepe/status/{merchantOrderId}', [PhonePeSdkV2Controller::class, 'orderStatus'])->name('phonepe.status');

// Create refund
Route::post('/api/phonepe/refund', [PhonePeSdkV2Controller::class, 'refund'])->name('phonepe.refund');

// Check refund status
Route::get('/api/phonepe/refund-status/{merchantRefundId}', [PhonePeSdkV2Controller::class, 'refundStatus'])->name('phonepe.refundStatus');


Route::post('/request-otp', [RequestOTPController::class, 'sendOTP']);
Route::post('/verify-otp', [VerifyOTPController::class, 'verify']);

Route::get('/ads', [AdsController::class, 'getAds']);
Route::post('/ads', [AdsController::class, 'store']);
Route::put('/ads/{num}', [AdsController::class, 'update']);
Route::delete('/ads/{num}', [AdsController::class, 'destroy']);

Route::get('/details', [DetailsController::class, 'getDetails']);

//Subscription calculate
Route::get('/calculate', [CalculatePlan::class, 'calculate']);

Route::get('/ppv-price', [PPVPriceCalculate::class, 'getPPVPrice']);

//Search
Route::get('/search', [MovieSearchController::class, 'search']);
Route::get('/movies/search', [SearchController::class, 'searchMovies']);

Route::post('/decrypt', [LinkController::class, 'decryptMessage']);
Route::get('/encrypt', [LinkController::class, 'encryptMessage']);

Route::get('/alsolike', [AlsoLikeController::class, 'alsoLike']);

Route::get('/price', [PlanPriceController::class, 'getPlanPrice']);

Route::post('/temp-payment', [TempPayment::class, 'storeTempPayment']);

Route::get('/episodes', [EpisodeController::class, 'getBySeason']);
Route::post('/episode-insert', [EpisodeController::class, 'insert']);
Route::put('/episode/{id}', [EpisodeController::class, 'update']);
Route::delete('/episode/{id}', [EpisodeController::class, 'destroy']);
Route::get('/episode/{id}', [EpisodeController::class, 'getById']);


Route::get('/update-dob', [UserController::class, 'updateDob']);

Route::post('/watch-position', [WatchPositionController::class, 'save']);
Route::get('/get-position', [WatchPositionController::class, 'getWatchPosition']);

Route::get('/update-token', [UserController::class, 'updateToken']);

Route::post('/update-login', [UserController::class, 'updateLogin']);

Route::post('/update-profile', [UserController::class, 'updateProfile']);

Route::post('/clear-device', [UserController::class, 'clearDeviceId']);

Route::get('/cash-free-payment', [CashFreeController::class, 'checkPayment']);
Route::post('/cash-free-order', [CashFreeController::class, 'createOrder']);

Route::get('/price-list', [PlanListController::class, 'getPriceList']);
Route::get('/invoice/{num}', [SubscriptionController::class, 'generateInvoice']);

Route::post('/send-fcm', [FCMNotificationController::class, 'send']);

Route::get('/stream', [StreamController::class, 'stream']);


Route::get('/preview', [AxinomLicense::class, 'previewMPD']);
Route::post('/axinom', [AxinomLicense::class, 'invokeWidevineCommonEncryption']);
Route::get('/generate-token', [MovieController::class, 'generateFromMpd']);


//Zonet
Route::post('/zonet-users/insert', [ZonetController::class, 'insert']);
Route::get('/zonet-users', [ZonetController::class, 'getAll']);
Route::delete('/zonet-users/{id}', [ZonetController::class, 'delete']);

// Zonet Subscription routes
Route::post('/zonet-subscriptions/insert', [ZonetController::class, 'insertSubscription']);
Route::get('/zonet-subscriptions/all', [ZonetController::class, 'getAllSubscriptions']);
Route::delete('/zonet-subscriptions/{id}', [ZonetController::class, 'deleteSubscription']);

// Zonet Operator routes
Route::prefix('zonet-operator')->group(function () {
    Route::post('/add', [ZonetOperatorController::class, 'add']);
    Route::put('/update/{num}', [ZonetOperatorController::class, 'update']);
    Route::delete('/delete/{num}', [ZonetOperatorController::class, 'delete']);
    Route::post('/login', [ZonetOperatorController::class, 'login']);
    Route::post('/operator/{operator_id}/wallet/topup', [ZonetOperatorController::class, 'topUpWallet']);
    Route::get('/operators', [ZonetOperatorController::class, 'getAll']);

});

Route::get('/admin/users', [AdminDashboardController::class, 'getUserStats']);
Route::get('/admin/movies', [AdminDashboardController::class, 'getMovieStats']);
Route::get('/admin/subscriptions', [AdminDashboardController::class, 'getSubscriptionStats']);

Route::get('/hls/check-folder', [HlsFolderController::class, 'check']);

