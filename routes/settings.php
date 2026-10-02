<?php

use App\Http\Controllers\ImportFlotteController;
use App\Http\Controllers\Settings\CommissionRegleController;
use App\Http\Controllers\Settings\Communications\EditCommunicationRuleController;
use App\Http\Controllers\Settings\Communications\UpdateCommunicationRuleController;
use App\Http\Controllers\Settings\Depenses\EditDepenseParametrageController;
use App\Http\Controllers\Settings\Depenses\UpdateDepenseParametrageController;
use App\Http\Controllers\Settings\Logistique\EditLogistiqueParametrageController;
use App\Http\Controllers\Settings\Logistique\UpdateLogistiqueParametrageController;
use App\Http\Controllers\Settings\Logistique\UpdateSiteLogistiqueParametrageController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\OrganisationController;
use App\Http\Controllers\Settings\Parametres\DownloadTemplateParametreController;
use App\Http\Controllers\Settings\Parametres\EditParametreController;
use App\Http\Controllers\Settings\Parametres\UpdateParametreController;
use App\Http\Controllers\Settings\Password\EditPasswordController;
use App\Http\Controllers\Settings\Password\UpdatePasswordController;
use App\Http\Controllers\Settings\Profile\DestroyProfileController;
use App\Http\Controllers\Settings\Profile\EditProfileController;
use App\Http\Controllers\Settings\Profile\UpdateProfileController;
use App\Http\Controllers\Settings\ReconfigurationPartagesController;
use App\Http\Controllers\Settings\ShowTwoFactorAuthenticationController;
use App\Http\Controllers\Settings\StockAjustementController;
use App\Http\Controllers\Settings\ThemeController;
use App\Http\Controllers\Settings\Ventes\EditVenteParametrageController;
use App\Http\Controllers\Settings\Ventes\UpdateVenteParametrageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', EditProfileController::class)->name('profile.edit');
    Route::patch('settings/profile', UpdateProfileController::class)->name('profile.update');
    Route::delete('settings/profile', DestroyProfileController::class)->name('profile.destroy');

    Route::get('settings/password', EditPasswordController::class)->name('user-password.edit');

    Route::put('settings/password', UpdatePasswordController::class)
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', ShowTwoFactorAuthenticationController::class)
        ->name('two-factor.show');

    Route::get('settings/organisation', [OrganisationController::class, 'edit'])->name('organisation.edit');
    Route::put('settings/organisation', [OrganisationController::class, 'update'])->name('organisation.update');

    Route::get('settings/parametres', EditParametreController::class)->name('parametres.edit');
    Route::get('settings/parametres/templates/{template}', DownloadTemplateParametreController::class)
        ->name('parametres.templates.download');
    Route::put('settings/parametres/{parametre}', UpdateParametreController::class)->name('parametres.update');

    Route::get('settings/modules', [ModuleController::class, 'edit'])->name('modules.edit');
    Route::patch('settings/modules', [ModuleController::class, 'toggle'])->name('modules.toggle');

    Route::get('settings/theme', [ThemeController::class, 'edit'])->name('theme.edit');
    Route::put('settings/theme', [ThemeController::class, 'update'])->name('theme.update');

    Route::get('settings/ventes', EditVenteParametrageController::class)->name('settings.ventes.edit');
    Route::put('settings/ventes', UpdateVenteParametrageController::class)->name('settings.ventes.update');

    Route::get('settings/logistique', EditLogistiqueParametrageController::class)->name('settings.logistique.edit');
    Route::put('settings/logistique', UpdateLogistiqueParametrageController::class)->name('settings.logistique.update');
    Route::patch('settings/logistique/sites/{site}', UpdateSiteLogistiqueParametrageController::class)->name('settings.logistique.sites.update');

    Route::get('settings/communications', EditCommunicationRuleController::class)->name('settings.communications.edit');
    Route::put('settings/communications', UpdateCommunicationRuleController::class)->name('settings.communications.update');

    Route::get('settings/commissions', [CommissionRegleController::class, 'index'])->name('settings.commissions.index');
    Route::get('settings/commissions/configuration', [CommissionRegleController::class, 'redirectConfiguration']);
    Route::post('settings/commissions/configuration', [CommissionRegleController::class, 'storeConfiguration'])->name('settings.commissions.configuration.store');
    Route::post('settings/commissions', [CommissionRegleController::class, 'store'])->name('settings.commissions.store');
    Route::post('settings/commissions/consultant', [CommissionRegleController::class, 'updateConsultant'])->name('settings.commissions.consultant.update');
    Route::post('settings/commissions/impact', [CommissionRegleController::class, 'apercuImpact'])->name('settings.commissions.impact');
    Route::get('settings/commissions/brouillons/{brouillon}', [ReconfigurationPartagesController::class, 'show'])->name('settings.commissions.brouillons.show');
    Route::put('settings/commissions/brouillons/{brouillon}/partages', [ReconfigurationPartagesController::class, 'enregistrerPartages'])->name('settings.commissions.brouillons.partages');
    Route::post('settings/commissions/brouillons/{brouillon}/publier', [ReconfigurationPartagesController::class, 'publier'])->name('settings.commissions.brouillons.publier');
    Route::delete('settings/commissions/brouillons/{brouillon}', [ReconfigurationPartagesController::class, 'abandonner'])->name('settings.commissions.brouillons.abandonner');

    Route::get('settings/produits', [StockAjustementController::class, 'edit'])->name('settings.produits');
    Route::put('settings/produits', [StockAjustementController::class, 'update'])->name('settings.produits.update');

    Route::get('settings/depenses', EditDepenseParametrageController::class)->name('settings.depenses');
    Route::put('settings/depenses/droits', UpdateDepenseParametrageController::class)->name('settings.depenses.droits');

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
