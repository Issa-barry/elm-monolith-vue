<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupe de capacité / groupe de chargement (ex: "Sachets", "Bouteilles") — unité de
 * regroupement utilisée par le moteur de capacité véhicule (VehiculeCapaciteService),
 * délibérément distincte de la Categorie du catalogue produit (Produits finis, Matières
 * premières, Consommables...) : deux organisations peuvent avoir un catalogue classé très
 * différemment de leurs contraintes réelles de chargement, ce sont deux axes indépendants.
 * Flat (pas de hiérarchie, contrairement à Categorie) : un groupe de capacité n'a pas besoin
 * de parent/enfant pour ce qu'il représente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupes_capacite', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom', 100);
            $table->timestamps();

            $table->unique(['organization_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupes_capacite');
    }
};
