<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\DeviceManagementController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/check', function () {
    return view('Test');
});

Route::get('/ads/{ad}', [AdsController::class, 'show'])->name('ads.show');

