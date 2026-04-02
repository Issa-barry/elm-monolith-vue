<?php

use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\ParametreController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/parametres', [ParametreController::class, 'edit'])->name('parametres.edit');
    Route::put('settings/parametres/{parametre}', [ParametreController::class, 'update'])->name('parametres.update');

    Route::get('settings/modules', [ModuleController::class, 'edit'])->name('modules.edit');
    Route::patch('settings/modules', [ModuleController::class, 'toggle'])->name('modules.toggle');
});
