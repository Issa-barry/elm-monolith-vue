<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, à côté de `prix_usine` (jamais modifié/renommé) : le métier distingue désormais
 * deux tarifs usine selon la catégorie tarifaire du véhicule de flotte qui livre la commande
 * (cf. TypeVehicule::categorie_tarifaire) — `prix_usine` reste le tarif "autres véhicules",
 * `prix_usine_tricycle` porte le tarif spécifique tricycle. Nullable : une variante existante
 * sans valeur retombe sur `prix_usine` (cf. PrixUsineResolver), aucune donnée existante à migrer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_variantes', function (Blueprint $table) {
            $table->unsignedBigInteger('prix_usine_tricycle')->nullable()->after('prix_usine');
        });
    }

    public function down(): void
    {
        Schema::table('produit_variantes', function (Blueprint $table) {
            $table->dropColumn('prix_usine_tricycle');
        });
    }
};
