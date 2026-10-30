<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

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
        Route::resource('clients', ClientController::class)->except('destroy');
        Route::inertia('services', 'admin/ComingSoon', ['section' => 'services'])->name('services');
        Route::inertia('job-search', 'admin/ComingSoon', ['section' => 'jobSearch'])->name('job-search');
        Route::inertia('settings', 'admin/ComingSoon', ['section' => 'settings'])->name('settings');
    });
});

require __DIR__.'/settings.php';
