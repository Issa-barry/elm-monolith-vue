<?php

use App\Features\ModuleFeature;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\LivreurRegistrationController;
use App\Http\Controllers\CashbackController;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\CommandeVenteController;
use App\Http\Controllers\CommandeVenteStatutController;
use App\Http\Controllers\CommissionLogistiqueController;
use App\Http\Controllers\CommissionPaymentController;
use App\Http\Controllers\CommissionVehiculeController;
use App\Http\Controllers\CommissionVenteController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContratController;
use App\Http\Controllers\DepenseController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EncaissementVenteController;
use App\Http\Controllers\EquipeLivraisonController;
use App\Http\Controllers\FactureVenteController;
use App\Http\Controllers\FraisCommissionPartController;
use App\Http\Controllers\LivreurController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PaieController;
use App\Http\Controllers\PaiementCommissionVenteController;
use App\Http\Controllers\PaiePaiementController;
use App\Http\Controllers\PaieVariableController;
use App\Http\Controllers\PdvController;
use App\Http\Controllers\PrestataireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\PropositionVehiculeController;
use App\Http\Controllers\ProprietaireController;
use App\Http\Controllers\ReceptionValidationAdminController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TransfertLogistiqueController;
use App\Http\Controllers\TransfertStatutController;
use App\Http\Controllers\TypeVehiculeController;
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
    Route::get('/register/livreur', [LivreurRegistrationController::class, 'create'])
        ->name('livreur.register');
    Route::post('/register/livreur', [LivreurRegistrationController::class, 'store'])
        ->name('livreur.register.store');
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
        'canRegister' => env('WEB_REGISTRATION_ENABLED', true) && ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
    ]);
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact', [
        'canRegister' => env('WEB_REGISTRATION_ENABLED', true) && ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
    ]);
})->name('contact');

Route::get('/help', function () {
    return Inertia::render('Help', [
        'canRegister' => env('WEB_REGISTRATION_ENABLED', true) && ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
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
        Route::get('pdv', [PdvController::class, 'index'])->name('pdv.index');
        Route::post('pdv/checkout', [PdvController::class, 'checkout'])->name('pdv.checkout');
        Route::patch('ventes/{commande_vente}/valider', [CommandeVenteController::class, 'valider'])->name('ventes.valider');
        Route::patch('ventes/{commande_vente}/annuler', [CommandeVenteController::class, 'annuler'])->name('ventes.annuler');
        Route::post('ventes/{commande_vente}/statut/avancer', [CommandeVenteStatutController::class, 'avancer'])->name('ventes.statut.avancer');
        Route::post('ventes/{commande_vente}/statut/annuler', [CommandeVenteStatutController::class, 'annuler'])->name('ventes.statut.annuler');
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
        // Propositions : doit être avant Route::resource('vehicules') pour éviter que
        // vehicules/{vehicule} intercepte /vehicules/propositions en priorité.
        Route::prefix('vehicules/propositions')->name('propositions-vehicules.')->group(function () {
            Route::get('/', [PropositionVehiculeController::class, 'index'])->name('index');
            Route::get('/{propositionVehicule}', [PropositionVehiculeController::class, 'show'])->name('show');
            Route::patch('/{propositionVehicule}/prendre-en-charge', [PropositionVehiculeController::class, 'priseEnCharge'])->name('prendre-en-charge');
            Route::patch('/{propositionVehicule}/demander-complement', [PropositionVehiculeController::class, 'demanderComplement'])->name('demander-complement');
            Route::patch('/{propositionVehicule}/rejeter', [PropositionVehiculeController::class, 'rejeter'])->name('rejeter');
            Route::post('/{propositionVehicule}/valider', [PropositionVehiculeController::class, 'valider'])->name('valider');
        });

        Route::resource('type-vehicules', TypeVehiculeController::class)->except(['show']);
        Route::resource('vehicules', VehiculeController::class);
        Route::post('vehicules/{vehicule}/frais', [VehiculeController::class, 'storeFrais'])->name('vehicules.frais.store');
        Route::patch('vehicules/{vehicule}/frais/{frais}', [VehiculeController::class, 'updateFrais'])->name('vehicules.frais.update');
        Route::delete('vehicules/{vehicule}/frais/{frais}', [VehiculeController::class, 'destroyFrais'])->name('vehicules.frais.destroy');
        Route::resource('proprietaires', ProprietaireController::class);
        // Livreurs : gestion centralisée depuis les Équipes (lecture seule + API modale)
        Route::get('livreurs', [LivreurController::class, 'index'])->name('livreurs.index');
        Route::post('livreurs', [LivreurController::class, 'store'])->name('livreurs.store');
        Route::patch('livreurs/{livreur}/toggle', [LivreurController::class, 'toggle'])->name('livreurs.toggle');
        Route::patch('livreurs/{livreur}/approuver', [LivreurController::class, 'approuver'])->name('livreurs.approuver');
        Route::delete('livreurs/{livreur}', [LivreurController::class, 'destroy'])->name('livreurs.destroy');

        Route::resource('equipes-livraison', EquipeLivraisonController::class);
    });

    // ── Module : Produits ─────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::PRODUITS)->group(function () {
        Route::resource('produits', ProduitController::class);
        Route::post('produits/{produit}/ajuster-stock', [ProduitController::class, 'ajusterStock'])
            ->name('produits.ajuster-stock');
        Route::get('produits/{produit}/historique', [ProduitController::class, 'historique'])
            ->name('produits.historique');
        Route::patch('produits/{produit}/archiver', [ProduitController::class, 'archiver'])
            ->name('produits.archiver');
    });

    // ── Module : Sites ────────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::SITES)->group(function () {
        Route::resource('sites', SiteController::class);
        Route::post('sites/{site}/invitations', [UserInvitationController::class, 'store'])
            ->name('sites.invitations.store')
            ->middleware('throttle:10,1');
    });

    // ── Comptes (super admin) ─────────────────────────────────────────────────
    Route::get('comptes', [AccountController::class, 'index'])->name('comptes.index');
    Route::patch('comptes/{user}/toggle-active', [AccountController::class, 'toggleActive'])->name('comptes.toggle-active');

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

    // ── Module : Dépenses opérationnelles ────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::DEPENSES)->group(function () {
        Route::resource('depenses', DepenseController::class);
        Route::patch('depenses/{depense}/soumettre', [DepenseController::class, 'soumettre'])->name('depenses.soumettre');
        Route::patch('depenses/{depense}/valider', [DepenseController::class, 'valider'])->name('depenses.valider');
        Route::patch('depenses/{depense}/rejeter', [DepenseController::class, 'rejeter'])->name('depenses.rejeter');
        Route::patch('depenses/{depense}/imputer', [DepenseController::class, 'imputer'])->name('depenses.imputer');
    });

    // ── Module : RH (Ressources humaines) ────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::RH)->group(function () {
        Route::resource('employes', EmployeController::class);
        Route::resource('contrats', ContratController::class)->except(['show']);

        // Paie
        Route::get('paie', [PaieController::class, 'index'])->name('paie.index');
        Route::get('paie/create', [PaieController::class, 'create'])->name('paie.create');
        Route::post('paie', [PaieController::class, 'store'])->name('paie.store');
        Route::get('paie/{paie}', [PaieController::class, 'show'])->name('paie.show');
        Route::delete('paie/{paie}', [PaieController::class, 'destroy'])->name('paie.destroy');
        Route::post('paie/{paie}/calculer', [PaieController::class, 'calculer'])->name('paie.calculer');
        Route::post('paie/{paie}/valider', [PaieController::class, 'valider'])->name('paie.valider');
        Route::post('paie/{paie}/paye', [PaieController::class, 'marquerPaye'])->name('paie.marquer-paye');
        Route::post('paie/{paie}/cloturer', [PaieController::class, 'cloturer'])->name('paie.cloturer');

        // Variables de paie
        Route::post('paie-lignes/{ligne}/variables', [PaieVariableController::class, 'store'])->name('paie-variables.store');
        Route::put('paie-variables/{variable}', [PaieVariableController::class, 'update'])->name('paie-variables.update');
        Route::delete('paie-variables/{variable}', [PaieVariableController::class, 'destroy'])->name('paie-variables.destroy');

        // Paiements de paie
        Route::post('paie-lignes/{ligne}/paiements', [PaiePaiementController::class, 'store'])->name('paie-paiements.store');
        Route::delete('paie-paiements/{paiement}', [PaiePaiementController::class, 'destroy'])->name('paie-paiements.destroy');
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
Route::middleware(['auth', 'role:client|proprietaire|livreur', 'active.livreur'])->prefix('client')->name('client.')->group(function () {
    Route::get('/pending', fn () => Inertia::render('client/Pending'))->name('pending');
    Route::get('/dashboard', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('/vehicules', [ClientDashboardController::class, 'vehicles'])->name('vehicles');
    Route::get('/gains', [ClientDashboardController::class, 'earnings'])->name('earnings');
    Route::get('/vehicules/{vehiculeId}/solde', [ClientDashboardController::class, 'vehicleBalance'])->name('vehicules.solde');
    Route::get('/qr-code', [ClientDashboardController::class, 'qrCode'])->name('qr-code');
    Route::get('/proposer-vehicule', [ClientDashboardController::class, 'proposals'])->name('propositions.index');
    Route::get('/profile', [ClientDashboardController::class, 'profile'])->name('profile');
    Route::post('/propositions-vehicules', [ClientDashboardController::class, 'storeVehicleProposal'])->name('propositions.store');
});

// ── Scan QR — accessible par staff et livreur (self-view) ─────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('livreurs/{livreur}', [LivreurController::class, 'show'])->name('livreurs.show');
    // Résolution ULID → URL fiche (pour le scanner USB qui lit le QR mobile)
    Route::get('scan/user/{userId}', \App\Http\Controllers\ScanUserController::class)->name('scan.user');
    // Résolution référence livraison → URL page backoffice (scanner QR de la livraison)
    Route::get('scan/livraison/{reference}', \App\Http\Controllers\ScanLivraisonController::class)->name('scan.livraison');
});

require __DIR__.'/settings.php';
