<?php

use App\Http\Controllers\AccountAccessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShowPublishedSheetController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('access', [AccountAccessController::class, 'store'])
    ->name('account-access.request');
Route::post('access/code', [AccountAccessController::class, 'consumeCode'])
    ->name('account-access.code');
Route::get('access/{challenge}/link/{token}', [AccountAccessController::class, 'consumeMagicLink'])
    ->name('account-access.magic');

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::livewire('sheets/create', 'pages::sheets.create')->name('sheets.create');
    Route::livewire('sheets/{sheet}/edit', 'pages::sheets.edit')->name('sheets.edit');
});

Route::get('sheets/{sheet:public_id}', ShowPublishedSheetController::class)
    ->name('sheets.show')
    ->missing(fn () => response()
        ->view('sheets.unavailable', status: 404)
        ->header('X-Robots-Tag', 'noindex, nofollow'));

require __DIR__.'/settings.php';
