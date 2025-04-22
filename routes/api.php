<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\AlsoLikeController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\CalculatePlan;
use App\Http\Controllers\CheckDeviceAvailable;
use App\Http\Controllers\DecryptionController;
use App\Http\Controllers\DetailsController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieSearchController;
use App\Http\Controllers\PaymentStatusController;
use App\Http\Controllers\PlanPriceController;
use App\Http\Controllers\PPVPriceCalculate;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RequestOTPController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TempPayment;
use App\Http\Controllers\UpdateUserDevice;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerifyOTPController;
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

Route::get('/get-subscription', [SubscriptionController::class, 'getSubscription']);
Route::get('/add-subscription', [SubscriptionController::class, 'addSubscription']);

Route::get('/movies', [MovieController::class, 'getMovies']);

//update device for new device or change device
Route::get('/device', [UpdateUserDevice::class, 'updateDevice']);

Route::get('/payment-status', [PaymentStatusController::class, 'processUserPayments']);

Route::post('/request-otp', [RequestOTPController::class, 'sendOTP']);
Route::post('/verify-otp', [VerifyOTPController::class, 'verify']);

Route::get('/ads', [AdsController::class, 'getAds']);

Route::get('/details', [DetailsController::class, 'getDetails']);

//Subscription calculate
Route::get('/calculate', [CalculatePlan::class, 'calculate']);

Route::get('/ppv-price', [PPVPriceCalculate::class, 'getPPVPrice']);

Route::get('/search', [MovieSearchController::class, 'search']);

Route::post('/decrypt', [DecryptionController::class, 'decryptMessage']);

Route::get('/alsolike', [AlsoLikeController::class, 'alsoLike']);

Route::get('/price', [PlanPriceController::class, 'getPlanPrice']);

Route::post('/temp-payment', [TempPayment::class, 'storeTempPayment']);

Route::get('/episodes', [EpisodeController::class, 'getBySeason']);

Route::get('/update-dob', [UserController::class, 'updateDob']);




