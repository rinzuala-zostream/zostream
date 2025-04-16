<?php

use App\Http\Controllers\CheckDeviceAvailable;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\PaymentStatusController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RequestOTPController;
use App\Http\Controllers\SubscriptionController;
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
    Route::post('/store', [DeviceManagementController::class, 'store']);
    Route::delete('/delete', [DeviceManagementController::class, 'delete']);
    Route::get('/get', [DeviceManagementController::class, 'get']);
    Route::put('/update', [DeviceManagementController::class, 'update']);
    Route::get('/available', [CheckDeviceAvailable::class, 'checkDeviceAvailability']);
});

Route::get('/test', function () {
    return response()->json(['message' => Crypt::encryptString(config('app.api_key'))]);
});

Route::get('/user', [UserController::class, 'getUserData']);
Route::get('/get-subscription', [SubscriptionController::class, 'getSubscription']);
Route::post('/movies', [MovieController::class, 'getMovies']);

//update device for new device or change device
Route::post('/device', [UpdateUserDevice::class, 'updateDevice']);

Route::get('/payment-status', [PaymentStatusController::class, 'processUserPayments']);

Route::post('/request-otp', [RequestOTPController::class, 'sendOTP']);

Route::post('/verify-otp', [VerifyOTPController::class, 'verify']);



