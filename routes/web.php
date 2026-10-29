<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', 'role:superadmin'])->group(function () {
    Route::inertia('admin/dashboard', 'Dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::inertia('clients', 'admin/ComingSoon', ['section' => 'clients'])->name('clients');
        Route::inertia('services', 'admin/ComingSoon', ['section' => 'services'])->name('services');
        Route::inertia('job-search', 'admin/ComingSoon', ['section' => 'jobSearch'])->name('job-search');
        Route::inertia('settings', 'admin/ComingSoon', ['section' => 'settings'])->name('settings');
    });
});

require __DIR__.'/settings.php';
