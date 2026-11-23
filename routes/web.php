<?php

use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientPhotoController;
use App\Http\Controllers\Admin\ClientServiceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\JobOrderController;
use App\Http\Controllers\Admin\JobOrderWorkerController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\WebsiteContentController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Client\DocumentController as ClientDocumentController;
use App\Http\Controllers\Client\PhotoController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\SessionController;
use App\Http\Controllers\Client\TrackingController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

// The public landing page: the same limit as the tracking pages, per visitor address.
Route::get('/', WelcomeController::class)->middleware('throttle:60,1')->name('home');

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
        Route::get('documents/{document}/download', [ClientDocumentController::class, 'download'])
            ->middleware('throttle:document-downloads')
            ->name('documents.download');
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    });

Route::middleware(['guest', 'throttle:10,1'])
    ->prefix('admin/auth/google')
    ->name('auth.google.')
    ->group(function () {
        Route::get('redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
        Route::get('callback', [GoogleAuthController::class, 'callback'])->name('callback');
    });

Route::middleware(['auth', 'role:superadmin'])->group(function () {
    Route::get('admin/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('clients/{client}/unlock', [ClientController::class, 'unlock'])->name('clients.unlock');
        Route::post('clients/{client}/photo', [ClientPhotoController::class, 'update'])->name('clients.photo.update');
        Route::delete('clients/{client}/photo', [ClientPhotoController::class, 'destroy'])->name('clients.photo.destroy');
        Route::resource('clients', ClientController::class);
        Route::resource('clients.services', ClientServiceController::class)
            ->only(['create', 'store', 'edit', 'update'])
            ->parameters(['services' => 'clientService'])
            ->scoped();
        Route::post('clients/{client}/services/{clientService:id}/cancel', [ClientServiceController::class, 'cancel'])
            ->scopeBindings()
            ->name('clients.services.cancel');
        Route::post('clients/{client}/services/{clientService:id}/payments', [PaymentController::class, 'store'])
            ->scopeBindings()
            ->name('clients.services.payments.store');
        Route::post('clients/{client}/services/{clientService:id}/documents', [DocumentController::class, 'store'])
            ->scopeBindings()
            ->name('clients.services.documents.store');
        Route::patch('clients/{client}/services/{clientService:id}/documents/{document:id}', [DocumentController::class, 'update'])
            ->scopeBindings()
            ->name('clients.services.documents.update');
        Route::get('clients/{client}/services/{clientService:id}/documents/{document:id}/download', [DocumentController::class, 'download'])
            ->scopeBindings()
            ->name('clients.services.documents.download');
        Route::post('clients/{client}/services/{clientService:id}/documents/{document:id}/file', [DocumentController::class, 'replace'])
            ->scopeBindings()
            ->name('clients.services.documents.replace');
        Route::delete('clients/{client}/services/{clientService:id}/documents/{document:id}', [DocumentController::class, 'destroy'])
            ->scopeBindings()
            ->name('clients.services.documents.destroy');
        Route::resource('services', ServiceController::class);
        Route::post('job-search/{jobOrder}/workers/lookup', [JobOrderWorkerController::class, 'lookup'])
            ->middleware('throttle:worker-lookups')
            ->name('job-search.workers.lookup');
        Route::post('job-search/{jobOrder}/workers', [JobOrderWorkerController::class, 'store'])->name('job-search.workers.store');
        Route::patch('job-search/{jobOrder}/workers/status', [JobOrderWorkerController::class, 'updateStatus'])->name('job-search.workers.status');
        Route::patch('job-search/{jobOrder}/workers/{worker}/cancel-hired', [JobOrderWorkerController::class, 'cancelHired'])->name('job-search.workers.cancel-hired');
        Route::get('job-search/{jobOrder}/close-preview', [JobOrderController::class, 'closePreview'])->name('job-search.close-preview');
        Route::post('job-search/{jobOrder}/close', [JobOrderController::class, 'close'])->name('job-search.close');
        Route::resource('job-search', JobOrderController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->parameters(['job-search' => 'jobOrder']);
        Route::get('settings', [SettingsController::class, 'index'])->name('settings');
        Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('settings.appearance.edit');
        Route::post('settings/appearance', [AppearanceController::class, 'update'])->name('settings.appearance.update');
        Route::get('settings/content', [WebsiteContentController::class, 'edit'])->name('settings.content.edit');
        Route::post('settings/content', [WebsiteContentController::class, 'update'])->name('settings.content.update');
        Route::post('settings/content/publish', [WebsiteContentController::class, 'publish'])->name('settings.content.publish');
    });
});

require __DIR__.'/settings.php';
