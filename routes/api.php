<?php

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

    // Inscription multi-étapes
    Route::prefix('register')->name('register.')->middleware('throttle:20,1')->group(function () {
        Route::post('lookup', RegisterLookupController::class)->name('lookup');
        Route::post('otp',    RegisterOtpController::class)->name('otp');
        Route::post('/',      RegisterController::class)->name('store');
    });

    // Réinitialisation de mot de passe par OTP
    Route::prefix('password')->name('password.')->middleware('throttle:10,1')->group(function () {
        Route::post('lookup', PasswordLookupController::class)->name('lookup');
        Route::post('verify', PasswordVerifyController::class)->name('verify');
        Route::post('reset',  ResetController::class)->name('reset');
    });

    // Routes protégées (token requis)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::get('me',      MeController::class)->name('me');
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

// ── Routes mobile ─────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/mobile')->group(function () {
        Route::get('vehicules/mine', \App\Http\Controllers\Api\Client\VehiculesController::class)
            ->name('client.vehicules.mine');
        Route::get('vehicules/{vehiculeId}/commissions', \App\Http\Controllers\Api\Client\VehiculeCommissionsController::class)
            ->name('client.vehicules.commissions');
        Route::get('vehicules/{vehiculeId}/frais', \App\Http\Controllers\Api\Client\VehiculeFraisController::class)
            ->name('client.vehicules.frais');
    });
    Route::get('gains/mine', \App\Http\Controllers\Api\Client\GainsController::class)
        ->name('client.gains.mine');
    Route::get('livraisons/en-cours', \App\Http\Controllers\Api\Client\LivraisonsEnCoursController::class)
        ->name('client.livraisons.en-cours');
});
