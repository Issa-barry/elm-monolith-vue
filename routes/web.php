<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeVenteController;
use App\Http\Controllers\FactureVenteController;
use App\Http\Controllers\EncaissementVenteController;
use App\Http\Controllers\LivreurController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\VehiculeController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\ProprietaireController;
use App\Http\Controllers\PrestataireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\VersementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::resource('clients', ClientController::class);
    Route::resource('prestataires', PrestataireController::class);
    Route::resource('produits', ProduitController::class);
    Route::resource('packings', PackingController::class);
    Route::patch('packings/{packing}/annuler', [PackingController::class, 'annuler'])->name('packings.annuler');
    Route::post('packings/{packing}/versements', [VersementController::class, 'store'])->name('packings.versements.store');
    Route::delete('packings/{packing}/versements/{versement}', [VersementController::class, 'destroy'])->name('packings.versements.destroy');
    Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update']);
    Route::resource('proprietaires', ProprietaireController::class);
    Route::resource('livreurs', LivreurController::class);
    Route::resource('vehicules', VehiculeController::class)->except(['show']);
    Route::resource('sites', SiteController::class)->except(['show']);
    Route::resource('ventes', CommandeVenteController::class)->except(['edit', 'update']);
    Route::patch('ventes/{commande_vente}/annuler', [CommandeVenteController::class, 'annuler'])->name('ventes.annuler');
    Route::get('factures', [FactureVenteController::class, 'index'])->name('factures.index');
    Route::post('factures/{facture_vente}/encaissements', [EncaissementVenteController::class, 'store'])->name('encaissements.store');
    Route::delete('encaissements/{encaissement_vente}', [EncaissementVenteController::class, 'destroy'])->name('encaissements.destroy');
});

require __DIR__.'/settings.php';
