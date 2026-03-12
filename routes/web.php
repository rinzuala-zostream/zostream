<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Redis;
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
        'status' => 'connected'
    ]);

    return "Firebase connected";
});

