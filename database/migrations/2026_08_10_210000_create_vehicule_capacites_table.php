<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacité par catégorie de produit, propre à un véhicule — seule et unique source de vérité
 * pour le plafond de chargement (décision produit du 17/08/2026, cf. VehiculeCapaciteService).
 * `vehicules.capacite_packs`/`capacite_bouteilles` sont des colonnes historiques désormais
 * mortes (plus jamais alimentées par le formulaire véhicule ni par l'import flotte) : cette
 * table ne s'y replie plus — un véhicule sans aucune ligne ici est simplement non plafonné,
 * jamais limité par une ancienne valeur globale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicule_capacites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicule_id')->constrained('vehicules')->cascadeOnDelete();
            $table->foreignUlid('categorie_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('capacite_max');
            $table->timestamps();

            $table->unique(['vehicule_id', 'categorie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicule_capacites');
    }
};
