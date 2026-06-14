<?php

use App\Http\Controllers\Api\Auth\BackofficeLoginController;
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
use App\Http\Controllers\Api\Backoffice\Logistique\CommissionsController;
use App\Http\Controllers\Api\Backoffice\Logistique\RessourcesController;
use App\Http\Controllers\Api\Backoffice\Logistique\SaisirReceptionController;
use App\Http\Controllers\Api\Backoffice\Logistique\TransfertsController;
use App\Http\Controllers\Api\Backoffice\Logistique\ValidationAdminController;
use App\Http\Controllers\Api\Backoffice\Logistique\ValiderReceptionController;
use App\Http\Controllers\Api\Backoffice\StatsController;
use App\Http\Controllers\Api\Client\GainsController;
use App\Http\Controllers\Api\Client\LivraisonsEnCoursController;
use App\Http\Controllers\Api\Client\VehiculeCommissionsController;
use App\Http\Controllers\Api\Client\VehiculeFraisController;
use App\Http\Controllers\Api\Client\VehiculesController;
use App\Http\Controllers\Api\Mobile\ChangePasswordController;
use App\Http\Controllers\Api\Mobile\ContactController;
use App\Http\Controllers\Api\Mobile\Logistique\ConfirmerDepartController;
use App\Http\Controllers\Api\Mobile\Logistique\DemarrerChargementController;
use App\Http\Controllers\Api\Mobile\Logistique\LivraisonDetailController;
use App\Http\Controllers\Api\Mobile\Logistique\MesLivraisonsController;
use App\Http\Controllers\Api\Mobile\Logistique\SaisirQuantitesChargeesController;
use App\Http\Controllers\Api\Mobile\NotificationsController;
use App\Http\Controllers\Api\Mobile\PushTokenController;
use App\Http\Controllers\Api\Mobile\ScanCommandeController;
use App\Http\Controllers\Api\Produits\ProduitController;
use App\Http\Controllers\Api\Produits\ProduitHistoriqueController;
use App\Models\Vehicule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
    $vehicule = Vehicule::find($vehiculeId);

    if (! $vehicule || ! $vehicule->photo_path) {
        abort(404);
    }

    $disk = Storage::disk('public');

    if (! $disk->exists($vehicule->photo_path)) {
        abort(404);
    }

    return $disk->response($vehicule->photo_path);
})->name('vehicule.photo');

// ── Auth backoffice mobile (publique — avant le middleware Sanctum) ────────────
Route::post('v1/backoffice/auth/login', BackofficeLoginController::class)
    ->middleware('throttle:10,1')
    ->name('api.backoffice.auth.login');

// ── Routes back-office mobile ─────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('v1/backoffice')->name('api.backoffice.')->group(function () {
    Route::get('me', App\Http\Controllers\Api\Backoffice\MeController::class)->name('me');
    Route::get('stats', StatsController::class)->name('stats');

    Route::apiResource('produits', ProduitController::class);
    Route::post('produits/{produit}/ajuster-stock', [ProduitController::class, 'ajusterStock'])
        ->name('produits.ajuster-stock');
    Route::get('produits/{produit}/historique', ProduitHistoriqueController::class)
        ->name('produits.historique');
    Route::patch('produits/{produit}/archiver', [ProduitController::class, 'archiver'])
        ->name('api.produits.archiver');
});

// ── Logistique backoffice ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('v1/backoffice')->name('api.backoffice.')->group(function () {
    Route::prefix('logistique')->name('logistique.')->group(function () {
        Route::get('ressources', RessourcesController::class)
            ->name('ressources');
        Route::get('transferts', [TransfertsController::class, 'index'])
            ->name('transferts.index');
        Route::post('transferts', [TransfertsController::class, 'store'])
            ->name('transferts.store');
        Route::get('transferts/{transfert}', [TransfertsController::class, 'show'])
            ->name('transferts.show');
        Route::put('transferts/{transfert}/reception', SaisirReceptionController::class)
            ->name('transferts.reception');
        Route::post('transferts/{transfert}/valider-reception', ValiderReceptionController::class)
            ->name('transferts.valider-reception');
        Route::post('transferts/{transfert}/validation-admin', ValidationAdminController::class)
            ->name('transferts.validation-admin');
        Route::get('commissions', [CommissionsController::class, 'index'])
            ->name('commissions.index');
        Route::get('commissions/{commission}', [CommissionsController::class, 'show'])
            ->name('commissions.show');
    });
});

// ── Routes mobile ─────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/mobile')->group(function () {
        Route::get('vehicules/mine', VehiculesController::class)
            ->name('client.vehicules.mine');
        Route::get('vehicules/{vehiculeId}/commissions', VehiculeCommissionsController::class)
            ->name('client.vehicules.commissions');
        Route::get('vehicules/{vehiculeId}/frais', VehiculeFraisController::class)
            ->name('client.vehicules.frais');
        Route::post('push-token', PushTokenController::class)
            ->name('client.push-token');
        Route::post('auth/change-password', ChangePasswordController::class)
            ->name('client.change-password');
        Route::get('livraisons/scan/{reference}', ScanCommandeController::class)
            ->name('client.livraisons.scan');
        Route::post('contact', ContactController::class)
            ->name('client.contact');

        // Logistique livreur
        Route::prefix('livraisons-transferts')->name('client.logistique.')->group(function () {
            Route::get('/', MesLivraisonsController::class)
                ->name('index');
            Route::get('{transfert}', LivraisonDetailController::class)
                ->name('show');
            Route::post('{transfert}/demarrer-chargement', DemarrerChargementController::class)
                ->name('demarrer-chargement');
            Route::put('{transfert}/quantites-chargees', SaisirQuantitesChargeesController::class)
                ->name('quantites-chargees');
            Route::post('{transfert}/confirmer-depart', ConfirmerDepartController::class)
                ->name('confirmer-depart');
        });
        Route::prefix('notifications')->name('client.notifications.')->group(function () {
            Route::get('/', [NotificationsController::class, 'index'])->name('index');
            Route::post('mark-all-read', [NotificationsController::class, 'markAllRead'])->name('mark-all-read');
            Route::post('{id}/read', [NotificationsController::class, 'markRead'])->name('mark-read');
        });
    });
    Route::get('gains/mine', GainsController::class)
        ->name('client.gains.mine');
    Route::get('livraisons/en-cours', LivraisonsEnCoursController::class)
        ->name('client.livraisons.en-cours');
});
