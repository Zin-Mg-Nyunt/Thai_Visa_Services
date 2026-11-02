<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientPhotoController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Client\PhotoController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\SessionController;
use App\Http\Controllers\Client\TrackingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('throttle:60,1')
    ->prefix('track')
    ->name('track.')
    ->group(function () {
        Route::get('{client:unique_token}', [TrackingController::class, 'show'])->name('show');
        Route::post('{client:unique_token}', [TrackingController::class, 'verify'])->name('verify');
    });

Route::get('client-photos/{client}', [PhotoController::class, 'show'])
    ->middleware('signed')
    ->name('client-photos.show');

Route::middleware('client.session')
    ->prefix('client/{client:unique_token}')
    ->name('client.')
    ->group(function () {
        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    });

Route::middleware(['guest', 'throttle:10,1'])
    ->prefix('admin/auth/google')
    ->name('auth.google.')
    ->group(function () {
        Route::get('redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
        Route::get('callback', [GoogleAuthController::class, 'callback'])->name('callback');
    });

Route::middleware(['auth', 'verified', 'role:superadmin'])->group(function () {
    Route::inertia('admin/dashboard', 'Dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('clients/{client}/unlock', [ClientController::class, 'unlock'])->name('clients.unlock');
        Route::post('clients/{client}/photo', [ClientPhotoController::class, 'update'])->name('clients.photo.update');
        Route::delete('clients/{client}/photo', [ClientPhotoController::class, 'destroy'])->name('clients.photo.destroy');
        Route::resource('clients', ClientController::class);
        Route::inertia('services', 'admin/ComingSoon', ['section' => 'services'])->name('services');
        Route::inertia('job-search', 'admin/ComingSoon', ['section' => 'jobSearch'])->name('job-search');
        Route::inertia('settings', 'admin/ComingSoon', ['section' => 'settings'])->name('settings');
    });
});

require __DIR__.'/settings.php';
