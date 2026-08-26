<?php

use App\Http\Controllers\ImportFlotteController;
use App\Http\Controllers\Settings\CommissionRegleController;
use App\Http\Controllers\Settings\DepenseParametrageController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\OrganisationController;
use App\Http\Controllers\Settings\ParametreController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\StockAjustementController;
use App\Http\Controllers\Settings\ThemeController;
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

    Route::get('settings/organisation', [OrganisationController::class, 'edit'])->name('organisation.edit');
    Route::put('settings/organisation', [OrganisationController::class, 'update'])->name('organisation.update');

    Route::get('settings/parametres', [ParametreController::class, 'edit'])->name('parametres.edit');
    Route::get('settings/parametres/templates/{template}', [ParametreController::class, 'downloadTemplate'])
        ->name('parametres.templates.download');
    Route::put('settings/parametres/{parametre}', [ParametreController::class, 'update'])->name('parametres.update');

    Route::get('settings/modules', [ModuleController::class, 'edit'])->name('modules.edit');
    Route::patch('settings/modules', [ModuleController::class, 'toggle'])->name('modules.toggle');

    Route::get('settings/theme', [ThemeController::class, 'edit'])->name('theme.edit');
    Route::put('settings/theme', [ThemeController::class, 'update'])->name('theme.update');

    Route::get('settings/ventes', [VenteParametrageController::class, 'edit'])->name('settings.ventes.edit');
    Route::put('settings/ventes', [VenteParametrageController::class, 'update'])->name('settings.ventes.update');

    Route::get('settings/commissions', [CommissionRegleController::class, 'index'])->name('settings.commissions.index');
    Route::get('settings/commissions/configuration', [CommissionRegleController::class, 'redirectConfiguration']);
    Route::post('settings/commissions/configuration', [CommissionRegleController::class, 'storeConfiguration'])->name('settings.commissions.configuration.store');
    Route::post('settings/commissions', [CommissionRegleController::class, 'store'])->name('settings.commissions.store');
    Route::post('settings/commissions/consultant', [CommissionRegleController::class, 'updateConsultant'])->name('settings.commissions.consultant.update');

    Route::get('settings/produits', [StockAjustementController::class, 'edit'])->name('settings.produits');
    Route::put('settings/produits', [StockAjustementController::class, 'update'])->name('settings.produits.update');

    Route::get('settings/depenses', [DepenseParametrageController::class, 'edit'])->name('settings.depenses');
    Route::put('settings/depenses/droits', [DepenseParametrageController::class, 'updateDroits'])->name('settings.depenses.droits');

    // La gestion des types de dépense a déménagé dans le module Dépenses (cf.
    // routes/web.php, groupe module:depenses) — cette page n'existe plus dans
    // les Paramètres. Redirection propre pour toute URL déjà en circulation
    // (favori, lien partagé) plutôt qu'un 404 sec.
    Route::redirect('settings/depense-types', '/backoffice/depenses/types');

    Route::prefix('settings/imports-flotte')->name('imports-flotte.')->group(function () {
        Route::get('/', [ImportFlotteController::class, 'index'])->name('index');
        Route::get('/nouveau', [ImportFlotteController::class, 'create'])->name('create');
        Route::post('/', [ImportFlotteController::class, 'store'])->name('store');
        Route::get('/modele', [ImportFlotteController::class, 'template'])->name('template');
        Route::get('/{importFlotte}', [ImportFlotteController::class, 'show'])->name('show');
        Route::post('/{importFlotte}/confirmer', [ImportFlotteController::class, 'confirm'])->name('confirm');
        Route::post('/{importFlotte}/relancer', [ImportFlotteController::class, 'retry'])->name('retry');
    });
});
