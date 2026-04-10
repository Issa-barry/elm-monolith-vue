<?php

use App\Features\ModuleFeature;
use App\Http\Controllers\CashbackController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\CommandeVenteController;
use App\Http\Controllers\CommissionPartController;
use App\Http\Controllers\CommissionVenteController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EncaissementVenteController;
use App\Http\Controllers\EquipeLivraisonController;
use App\Http\Controllers\FactureVenteController;
use App\Http\Controllers\LivreurController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PrestataireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ProprietaireController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehiculeController;
use App\Http\Controllers\VersementCommissionController;
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

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'role:super_admin|admin_entreprise|manager|commerciale|comptable', 'require.site'])->name('dashboard');

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
        Route::get('commissions/{commission_vente}', [CommissionVenteController::class, 'show'])->name('commissions.show');

        // Versements par part
        Route::post('commissions/{commission}/parts/{part}/versements', [VersementCommissionController::class, 'store'])->name('commissions.parts.versements.store');
        Route::delete('versements-commissions/{versement_commission}', [VersementCommissionController::class, 'destroy'])->name('commissions.versements.destroy');

        // Frais propriétaire (par part)
        Route::patch('commissions/{commission}/parts/{part}/frais', [CommissionPartController::class, 'updateFrais'])->name('commissions.parts.frais.update');

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
        Route::resource('vehicules', VehiculeController::class)->except(['show']);
        Route::resource('proprietaires', ProprietaireController::class);
        // Livreurs : gestion centralisée depuis les Équipes (lecture seule + API modale)
        Route::get('livreurs', [LivreurController::class, 'index'])->name('livreurs.index');
        Route::post('livreurs', [LivreurController::class, 'store'])->name('livreurs.store');
        Route::patch('livreurs/{livreur}/toggle', [LivreurController::class, 'toggle'])->name('livreurs.toggle');
        Route::delete('livreurs/{livreur}', [LivreurController::class, 'destroy'])->name('livreurs.destroy');

        Route::resource('equipes-livraison', EquipeLivraisonController::class)->except(['show']);
    });

    // ── Module : Produits ─────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::PRODUITS)->group(function () {
        Route::resource('produits', ProduitController::class);
    });

    // ── Module : Sites ────────────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::SITES)->group(function () {
        Route::resource('sites', SiteController::class);
    });

    // ── Module : Utilisateurs ─────────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::UTILISATEURS)->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.update-password');
        Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update']);
    });

    // ── Module : Cashback clients ─────────────────────────────────────────────
    Route::middleware('module:'.ModuleFeature::CASHBACK)->group(function () {
        Route::get('cashback', [CashbackController::class, 'index'])->name('cashback.index');
        Route::patch('cashback/{cashbackTransaction}/valider', [CashbackController::class, 'valider'])->name('cashback.valider');
        Route::patch('cashback/{cashbackTransaction}/verser', [CashbackController::class, 'verser'])->name('cashback.verser');
    });
});

// ── Espace client ─────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:client'])->prefix('client')->name('client.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Client\ClientDashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
