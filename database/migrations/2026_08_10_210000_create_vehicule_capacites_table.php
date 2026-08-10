<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacité par catégorie de produit, propre à un véhicule (override) — cf. table jumelle
 * type_vehicule_capacites pour la capacité par défaut du type. Additive : ne touche pas
 * à vehicules.capacite_packs (ancien plafond global, conservé). Voir VehiculeCapaciteService :
 * un véhicule sans aucune ligne ici continue d'utiliser exclusivement capacite_packs — le
 * nouveau système par catégorie n'entre en jeu que si l'organisation l'a explicitement
 * configuré, pour ne rien casser sur la flotte existante.
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
