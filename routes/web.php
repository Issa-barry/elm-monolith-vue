<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\CommandeVenteController;
use App\Http\Controllers\CommissionVenteController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EncaissementVenteController;
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
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact');
})->name('contact');

Route::post('contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'role:super_admin|admin_entreprise|manager|commerciale|comptable'])->name('dashboard');

// Espace staff
Route::middleware(['auth', 'role:super_admin|admin_entreprise|manager|commerciale|comptable'])->group(function () {
    Route::get('contact-messages/unread-count', [ContactController::class, 'unreadCount'])->name('contact-messages.unread-count');
    Route::patch('contact-messages/{contactMessage}/read', [ContactController::class, 'markRead'])->name('contact-messages.read');
    Route::resource('clients', ClientController::class);
    Route::resource('prestataires', PrestataireController::class);
    Route::resource('produits', ProduitController::class);
    Route::resource('packings', PackingController::class);
    Route::patch('packings/{packing}/annuler', [PackingController::class, 'annuler'])->name('packings.annuler');
    Route::post('packings/{packing}/versements', [VersementController::class, 'store'])->name('packings.versements.store');
    Route::delete('packings/{packing}/versements/{versement}', [VersementController::class, 'destroy'])->name('packings.versements.destroy');
    Route::resource('users', UserController::class)->except(['show']);
    Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update']);
    Route::resource('proprietaires', ProprietaireController::class);
    Route::resource('livreurs', LivreurController::class);
    Route::resource('vehicules', VehiculeController::class)->except(['show']);
    Route::resource('sites', SiteController::class);
    Route::resource('ventes', CommandeVenteController::class)->except(['edit', 'update']);
    Route::patch('ventes/{commande_vente}/annuler', [CommandeVenteController::class, 'annuler'])->name('ventes.annuler');
    Route::resource('achats', CommandeAchatController::class)->except(['edit', 'update']);
    Route::patch('achats/{achat}/receptionner', [CommandeAchatController::class, 'receptionner'])->name('achats.receptionner');
    Route::patch('achats/{achat}/annuler', [CommandeAchatController::class, 'annuler'])->name('achats.annuler');
    Route::get('achats/{achat}/pdf', [CommandeAchatController::class, 'pdf'])->name('achats.pdf');
    Route::get('factures', [FactureVenteController::class, 'index'])->name('factures.index');
    Route::get('commissions', [CommissionVenteController::class, 'index'])->name('commissions.index');
    Route::post('commissions/{commission_vente}/versements', [VersementCommissionController::class, 'store'])->name('commissions.versements.store');
    Route::delete('versements-commissions/{versement_commission}', [VersementCommissionController::class, 'destroy'])->name('commissions.versements.destroy');
    Route::post('factures/{facture_vente}/encaissements', [EncaissementVenteController::class, 'store'])->name('encaissements.store');
    Route::delete('encaissements/{encaissement_vente}', [EncaissementVenteController::class, 'destroy'])->name('encaissements.destroy');
});

// Espace client
Route::middleware(['auth', 'role:client'])->prefix('client')->name('client.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Client\ClientDashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
