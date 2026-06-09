<?php

use App\Http\Controllers\Api\Auth\CheckPhoneController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\PasswordReset\LookupController as PasswordLookupController;
use App\Http\Controllers\Api\Auth\PasswordReset\ResetController;
use App\Http\Controllers\Api\Auth\PasswordReset\VerifyController as PasswordVerifyController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\RegisterLookupController;
use App\Http\Controllers\Api\Auth\RegisterOtpController;
use Illuminate\Support\Facades\Route;

// ── Authentification ──────────────────────────────────────────────────────────
Route::prefix('auth')->name('api.auth.')->group(function () {

    // Connexion
    Route::post('login', LoginController::class)->name('login')
        ->middleware('throttle:10,1');

    // Vérification email (accessible sans authentification)
    Route::get('verify-email/{token}', EmailVerificationController::class)
        ->name('verify-email');

    // Inscription multi-étapes
    Route::prefix('register')->name('register.')->middleware('throttle:20,1')->group(function () {
        // Nouveau flow (vérification email)
        Route::post('check-phone', CheckPhoneController::class)->name('check-phone');
        Route::post('/', RegisterController::class)->name('store');

        // Ancien flow OTP (rétro-compatibilité)
        Route::post('lookup', RegisterLookupController::class)->name('lookup');
        Route::post('otp', RegisterOtpController::class)->name('otp');
    });

    // Réinitialisation de mot de passe par OTP
    Route::prefix('password')->name('password.')->middleware('throttle:10,1')->group(function () {
        Route::post('lookup', PasswordLookupController::class)->name('lookup');
        Route::post('verify', PasswordVerifyController::class)->name('verify');
        Route::post('reset', ResetController::class)->name('reset');
    });

    // Routes protégées (token requis)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::get('me', MeController::class)->name('me');
    });
});

// ── Photo véhicule (publique — contourne le symlink storage Windows) ─────────
Route::get('vehicules/{vehiculeId}/photo', function (string $vehiculeId) {
    $vehicule = \App\Models\Vehicule::find($vehiculeId);

    if (! $vehicule || ! $vehicule->photo_path) {
        abort(404);
    }

    $disk = \Illuminate\Support\Facades\Storage::disk('public');

    if (! $disk->exists($vehicule->photo_path)) {
        abort(404);
    }

    return $disk->response($vehicule->photo_path);
})->name('vehicule.photo');

// ── Routes back-office mobile ─────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('v1/backoffice')->name('api.backoffice.')->group(function () {
    Route::get('me', \App\Http\Controllers\Api\Backoffice\MeController::class)->name('me');
});

// ── Routes mobile ─────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/mobile')->group(function () {
        Route::get('vehicules/mine', \App\Http\Controllers\Api\Client\VehiculesController::class)
            ->name('client.vehicules.mine');
        Route::get('vehicules/{vehiculeId}/commissions', \App\Http\Controllers\Api\Client\VehiculeCommissionsController::class)
            ->name('client.vehicules.commissions');
        Route::get('vehicules/{vehiculeId}/frais', \App\Http\Controllers\Api\Client\VehiculeFraisController::class)
            ->name('client.vehicules.frais');
        Route::post('push-token', \App\Http\Controllers\Api\Mobile\PushTokenController::class)
            ->name('client.push-token');
        Route::post('auth/change-password', \App\Http\Controllers\Api\Mobile\ChangePasswordController::class)
            ->name('client.change-password');
        Route::get('livraisons/scan/{reference}', \App\Http\Controllers\Api\Mobile\ScanCommandeController::class)
            ->name('client.livraisons.scan');
        Route::post('contact', \App\Http\Controllers\Api\Mobile\ContactController::class)
            ->name('client.contact');
        Route::prefix('notifications')->name('client.notifications.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\Mobile\NotificationsController::class, 'index'])->name('index');
            Route::post('mark-all-read', [\App\Http\Controllers\Api\Mobile\NotificationsController::class, 'markAllRead'])->name('mark-all-read');
            Route::post('{id}/read', [\App\Http\Controllers\Api\Mobile\NotificationsController::class, 'markRead'])->name('mark-read');
        });
    });
    Route::get('gains/mine', \App\Http\Controllers\Api\Client\GainsController::class)
        ->name('client.gains.mine');
    Route::get('livraisons/en-cours', \App\Http\Controllers\Api\Client\LivraisonsEnCoursController::class)
        ->name('client.livraisons.en-cours');
});
