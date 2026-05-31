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
