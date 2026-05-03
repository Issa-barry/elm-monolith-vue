<?php

use App\Http\Controllers\Settings\DepenseTypeController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\ParametreController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\VenteParametrageController;
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
    Route::get('settings/parametres/templates/{template}', [ParametreController::class, 'downloadTemplate'])
        ->name('parametres.templates.download');
    Route::put('settings/parametres/{parametre}', [ParametreController::class, 'update'])->name('parametres.update');

    Route::get('settings/modules', [ModuleController::class, 'edit'])->name('modules.edit');
    Route::patch('settings/modules', [ModuleController::class, 'toggle'])->name('modules.toggle');

    Route::get('settings/ventes', [VenteParametrageController::class, 'edit'])->name('settings.ventes.edit');
    Route::put('settings/ventes', [VenteParametrageController::class, 'update'])->name('settings.ventes.update');

    Route::prefix('settings/depense-types')->name('settings.depense-types.')->group(function () {
        Route::get('/', [DepenseTypeController::class, 'index'])->name('index');
        Route::post('/', [DepenseTypeController::class, 'store'])->name('store');
        Route::put('/{depense_type}', [DepenseTypeController::class, 'update'])->name('update');
        Route::patch('/{depense_type}/toggle', [DepenseTypeController::class, 'toggle'])->name('toggle');
        Route::delete('/{depense_type}', [DepenseTypeController::class, 'destroy'])->name('destroy');
    });
});
