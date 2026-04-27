<?php

use App\Features\ModuleFeature;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\CashbackController;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\CommandeVenteController;
use App\Http\Controllers\CommissionLogistiqueController;
use App\Http\Controllers\CommissionPaymentController;
use App\Http\Controllers\CommissionVehiculeController;
use App\Http\Controllers\CommissionVenteController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EncaissementVenteController;
use App\Http\Controllers\EquipeLivraisonController;
use App\Http\Controllers\FactureVenteController;
use App\Http\Controllers\FraisCommissionPartController;
use App\Http\Controllers\LivreurController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PaiementCommissionVenteController;
use App\Http\Controllers\PrestataireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ProprietaireController;
use App\Http\Controllers\ReceptionValidationAdminController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TransfertLogistiqueController;
use App\Http\Controllers\TransfertStatutController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\VehiculeController;
use App\Http\Controllers\VersementCommissionController;
use App\Http\Controllers\VersementCommissionLogistiqueController;
use App\Http\Controllers\VersementController;
use App\Services\ModuleService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Inscription multi-étapes (lookup téléphone + vérification OTP) ─────────────
Route::middleware('guest')->group(function () {
    Route::post('/register/lookup', \App\Http\Controllers\Auth\RegisterLookupController::class)
        ->name('register.lookup');
    Route::post('/register/otp/verify', \App\Http\Controllers\Auth\RegisterOtpController::class)
        ->name('register.otp.verify');
});

// ── Onboarding via lien d'invitation ─────────────────────────────────────────
Route::get('/invitations/accept/{token}', [AcceptInvitationController::class, 'show'])
    ->name('invitations.accept')
    ->middleware('throttle:20,1');
Route::post('/invitations/accept/{token}/phone', [AcceptInvitationController::class, 'checkPhone'])
    ->name('invitations.accept.phone')
    ->middleware('throttle:10,1');
Route::post('/invitations/accept/{token}/otp', [AcceptInvitationController::class, 'verifyOtp'])
    ->name('invitations.accept.otp')
    ->middleware('throttle:10,1');
Route::post('/invitations/accept/{token}', [AcceptInvitationController::class, 'accept'])
    ->name('invitations.accept.store')
    ->middleware('throttle:5,1');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
    ]);
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact', [
        'canRegister' => ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
    ]);
})->name('contact');

Route::get('/help', function () {
    return Inertia::render('Help', [
        'canRegister' => ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
    ]);
})->name('help');

Route::post('contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'role:super_admin|admin_entreprise|manager|commerciale|comptable', 'require.site'])
    ->name('dashboard');

// ── Espace staff ──────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:super_admin|admin_entreprise|manager|commerciale|comptable', 'require.site'])->group(function () {

    // Messages de contact
    Route::get('contact-messages/unread-count', [ContactController::class, 'unreadCount'])->name('contact-messages.unread-count');
    Route::patch('contact-messages/{contactMessage}/read', [ContactController::class, 'markRead'])->name('contact-messages.read');

    // Clients
    Route::resource('clients', ClientController::class);

    // ── Module : Ventes ───────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::VENTES)->group(function () {
        Route::resource('ventes', CommandeVenteController::class)->except([]);
        Route::patch('ventes/{commande_vente}/valider', [CommandeVenteController::class, 'valider'])->name('ventes.valider');
        Route::patch('ventes/{commande_vente}/annuler', [CommandeVenteController::class, 'annuler'])->name('ventes.annuler');
        Route::get('factures', [FactureVenteController::class, 'index'])->name('factures.index');

        // Commissions
        Route::get('commissions', [CommissionVenteController::class, 'index'])->name('commissions.index');
        Route::get('commissions/beneficiaires/{type}/{beneficiaireId}', [CommissionVenteController::class, 'showBeneficiaire'])->name('commissions.beneficiaires.show');
        Route::get('commissions/{commission_vente}', [CommissionVenteController::class, 'show'])->name('commissions.show');

        // Paiement groupé bénéficiaire (nouveau workflow)
        Route::post('commissions/beneficiaires/{type}/{beneficiaireId}/paiements', [PaiementCommissionVenteController::class, 'store'])->name('commissions.beneficiaires.paiements.store');

        // Frais par part (livreur)
        Route::patch('commissions/parts/{part}/frais', [FraisCommissionPartController::class, 'update'])->name('commissions.parts.frais.update');

        // Versements par part (ancien système — conservé pour compatibilité)
        Route::post('commissions/{commission}/parts/{part}/versements', [VersementCommissionController::class, 'store'])->name('commissions.parts.versements.store');
        Route::delete('versements-commissions/{versement_commission}', [VersementCommissionController::class, 'destroy'])->name('commissions.versements.destroy');

        // Encaissements factures
        Route::post('factures/{facture_vente}/encaissements', [EncaissementVenteController::class, 'store'])->name('encaissements.store');
        Route::delete('encaissements/{encaissement_vente}', [EncaissementVenteController::class, 'destroy'])->name('encaissements.destroy');
    });

    // ── Module : Achats ───────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::ACHATS)->group(function () {
        Route::resource('achats', CommandeAchatController::class)->except(['edit', 'update']);
        Route::patch('achats/{achat}/receptionner', [CommandeAchatController::class, 'receptionner'])->name('achats.receptionner');
        Route::patch('achats/{achat}/annuler', [CommandeAchatController::class, 'annuler'])->name('achats.annuler');
        Route::get('achats/{achat}/pdf', [CommandeAchatController::class, 'pdf'])->name('achats.pdf');
    });

    // ── Module : Packings ─────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::PACKINGS)->group(function () {
        Route::resource('packings', PackingController::class);
        Route::patch('packings/{packing}/annuler', [PackingController::class, 'annuler'])->name('packings.annuler');
        Route::post('packings/{packing}/versements', [VersementController::class, 'store'])->name('packings.versements.store');
        Route::delete('packings/{packing}/versements/{versement}', [VersementController::class, 'destroy'])->name('packings.versements.destroy');
    });

    // ── Module : Prestataires ─────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::PRESTATAIRES)->group(function () {
        Route::resource('prestataires', PrestataireController::class);
    });

    // ── Module : Véhicules ────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::VEHICULES)->group(function () {
        Route::resource('vehicules', VehiculeController::class);
        Route::post('vehicules/{vehicule}/frais', [VehiculeController::class, 'storeFrais'])->name('vehicules.frais.store');
        Route::patch('vehicules/{vehicule}/frais/{frais}', [VehiculeController::class, 'updateFrais'])->name('vehicules.frais.update');
        Route::delete('vehicules/{vehicule}/frais/{frais}', [VehiculeController::class, 'destroyFrais'])->name('vehicules.frais.destroy');
        Route::resource('proprietaires', ProprietaireController::class);
        // Livreurs : gestion centralisée depuis les Équipes (lecture seule + API modale)
        Route::get('livreurs', [LivreurController::class, 'index'])->name('livreurs.index');
        Route::post('livreurs', [LivreurController::class, 'store'])->name('livreurs.store');
        Route::patch('livreurs/{livreur}/toggle', [LivreurController::class, 'toggle'])->name('livreurs.toggle');
        Route::delete('livreurs/{livreur}', [LivreurController::class, 'destroy'])->name('livreurs.destroy');

        Route::resource('equipes-livraison', EquipeLivraisonController::class);
    });

    // ── Module : Produits ─────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::PRODUITS)->group(function () {
        Route::resource('produits', ProduitController::class);
    });

    // ── Module : Sites ────────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::SITES)->group(function () {
        Route::resource('sites', SiteController::class);
        Route::post('sites/{site}/invitations', [UserInvitationController::class, 'store'])
            ->name('sites.invitations.store')
            ->middleware('throttle:10,1');
    });

    // ── Module : Utilisateurs ─────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::UTILISATEURS)->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.update-password');
        Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update']);
        Route::post('invitations/{invitation}/resend', [UserInvitationController::class, 'resend'])
            ->name('invitations.resend');
        Route::delete('invitations/{invitation}', [UserInvitationController::class, 'destroy'])
            ->name('invitations.destroy');
    });

    // ── Module : Cashback clients ─────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::CASHBACK)->group(function () {
        Route::get('cashback', [CashbackController::class, 'index'])->name('cashback.index');
        Route::patch('cashback/{cashbackTransaction}/valider', [CashbackController::class, 'valider'])->name('cashback.valider');
        Route::patch('cashback/{cashbackTransaction}/verser', [CashbackController::class, 'verser'])->name('cashback.verser');
    });

    // ── Module : Logistique inter-sites ───────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::LOGISTIQUE)->group(function () {
        // Redirection rétro-compatibilité : /logistique → /logistique/transferts
        Route::get('logistique', function () {
            return redirect()->route('logistique.transferts.index', [], 302);
        })->name('logistique.index');

        // Vues index séparées (statiques, AVANT le wildcard {transfert_logistique})
        Route::get('logistique/transferts', [TransfertLogistiqueController::class, 'indexTransferts'])->name('logistique.transferts.index');
        Route::get('logistique/receptions', [TransfertLogistiqueController::class, 'indexReceptions'])->name('logistique.receptions.index');

        // Commissions logistiques — par livreur (système global)
        Route::get('logistique/commissions', [CommissionVehiculeController::class, 'index'])
            ->name('logistique.commissions.index');
        Route::get('logistique/commissions/livreurs/{livreurId}', [CommissionVehiculeController::class, 'showLivreur'])
            ->name('logistique.commissions.livreur');
        Route::post('logistique/commissions/livreurs/{livreurId}/paiements', [CommissionPaymentController::class, 'storeLivreur'])
            ->name('logistique.commissions.livreur.paiements');

        // Rétro-compat : accès par véhicule (depuis page transfert Show)
        Route::get('logistique/commissions/vehicules/{vehicule}', [CommissionVehiculeController::class, 'show'])
            ->name('logistique.commissions.vehicule');
        Route::get('logistique/commissions/vehicules/{vehicule}/beneficiaires/{type}/{beneficiaireId}', [CommissionVehiculeController::class, 'releve'])
            ->name('logistique.commissions.releve');
        Route::post('logistique/commissions/vehicules/{vehicule}/paiements', [CommissionPaymentController::class, 'store'])
            ->name('logistique.commissions.paiements.store');

        // Rétro-compat : accès direct par commission (page transfert Show)
        Route::get('logistique/commissions/detail/{commission_logistique}', [CommissionLogistiqueController::class, 'show'])
            ->name('logistique.commissions.show');

        Route::get('logistique/creer', [TransfertLogistiqueController::class, 'create'])->name('logistique.create');
        Route::post('logistique', [TransfertLogistiqueController::class, 'store'])->name('logistique.store');
        Route::get('logistique/{transfert_logistique}', [TransfertLogistiqueController::class, 'show'])->name('logistique.show');
        Route::get('logistique/{transfert_logistique}/editer', [TransfertLogistiqueController::class, 'edit'])->name('logistique.edit');
        Route::put('logistique/{transfert_logistique}', [TransfertLogistiqueController::class, 'update'])->name('logistique.update');
        Route::delete('logistique/{transfert_logistique}', [TransfertLogistiqueController::class, 'destroy'])->name('logistique.destroy');

        // Transitions de statut
        Route::post('logistique/{transfert_logistique}/statut/avancer', [TransfertStatutController::class, 'avancer'])->name('logistique.statut.avancer');
        Route::post('logistique/{transfert_logistique}/statut/annuler', [TransfertStatutController::class, 'annuler'])->name('logistique.statut.annuler');

        // Validation admin de la réception (génère la commission automatiquement)
        Route::post('logistique/{transfert_logistique}/validation-reception', [ReceptionValidationAdminController::class, 'store'])->name('logistique.validation-reception.store');

        // Commission logistique (accès direct, backward compat)
        Route::post('logistique/{transfert_logistique}/commission', [CommissionLogistiqueController::class, 'store'])->name('logistique.commission.store');

        // Versements de parts de commission
        Route::post('commissions-logistique/parts/{part}/versements', [VersementCommissionLogistiqueController::class, 'store'])
            ->name('logistique.commission.versements.store');
    });
});

// ── Espace client ─────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:client|proprietaire|livreur'])->prefix('client')->name('client.')->group(function () {
    Route::get('/dashboard', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('/vehicules', [ClientDashboardController::class, 'vehicles'])->name('vehicles');
    Route::get('/gains', [ClientDashboardController::class, 'earnings'])->name('earnings');
    Route::get('/proposer-vehicule', [ClientDashboardController::class, 'proposals'])->name('propositions.index');
    Route::get('/profile', [ClientDashboardController::class, 'profile'])->name('profile');
    Route::post('/propositions-vehicules', [ClientDashboardController::class, 'storeVehicleProposal'])->name('propositions.store');
});

require __DIR__.'/settings.php';
