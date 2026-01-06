<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome')->name('home');
});


Route::get('/test', function () {
    return 'Laravel is working!';
});


// routes/web.php
Route::get('/debug/flutterwave-configg', function () {
    return [
        'config' => config('services.flutterwave'),
        'env' => [
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        ],
        'loaded' => !empty(config('services.flutterwave.secret_key'))
    ];
});
