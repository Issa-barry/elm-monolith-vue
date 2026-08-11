<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Véhicule d'un partenaire (Client::type = PARTENAIRE), volontairement léger et hors flotte
 * gérée : jamais d'équipe, de propriétaire de flotte, de capacités, de dépenses ni de
 * commissions. Sert uniquement à mémoriser une information de transport facultative — tous
 * les champs métier sont nullable, seul client_id est requis. Ne réutilise pas `Vehicule`, qui
 * porte des responsabilités de flotte incompatibles avec ce besoin (cf. analyse du modèle
 * véhicules/partenaires).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_vehicules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('libelle', 100)->nullable();
            $table->string('immatriculation', 20)->nullable();
            $table->string('chauffeur_nom', 100)->nullable();
            $table->string('chauffeur_telephone', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_vehicules');
    }
};
