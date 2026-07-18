<?php

use App\Http\Controllers\AccountAccessController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('access', [AccountAccessController::class, 'store'])
        ->name('account-access.request');
    Route::post('access/code', [AccountAccessController::class, 'consumeCode'])
        ->name('account-access.code');
    Route::get('access/{challenge}/link/{token}', [AccountAccessController::class, 'consumeMagicLink'])
        ->name('account-access.magic');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
