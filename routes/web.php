<?php

use App\Http\Controllers\AccountAccessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShowPublishedSheetController;
use App\Http\Controllers\ShowVerifiedParticipationController;
use App\Http\Middleware\PreventSearchIndexing;
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
    Route::livewire('signups/{signup}/edit', 'pages::signups.edit')
        ->middleware(PreventSearchIndexing::class)
        ->name('signups.edit');
    Route::livewire('sheets/create', 'pages::sheets.create')->name('sheets.create');
    Route::livewire('sheets/{sheet}/edit', 'pages::sheets.edit')->name('sheets.edit');
    Route::livewire('sheets/{sheet}/signups', 'pages::sheets.signups')
        ->middleware(PreventSearchIndexing::class)
        ->name('sheets.signups');
    Route::get('sheets/{sheet:public_id}/participate', ShowVerifiedParticipationController::class)
        ->name('sheets.participate')
        ->missing(fn () => response()
            ->view('sheets.unavailable', status: 404)
            ->header('X-Robots-Tag', 'noindex, nofollow'));
});

Route::get('sheets/{sheet:public_id}', ShowPublishedSheetController::class)
    ->name('sheets.show')
    ->missing(fn () => response()
        ->view('sheets.unavailable', status: 404)
        ->header('X-Robots-Tag', 'noindex, nofollow'));

require __DIR__.'/settings.php';
