<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel de fonctions RH (métier de la personne), STRICTEMENT isolé par organisation —
 * décision finale (2026-08-21) : aucune fonction système/prédéfinie, aucune suggestion de profil
 * d'accès. Chaque organisation crée et gère son propre vocabulaire ("Gérant de dépôt" pour l'une,
 * "Chef de magasin" pour une autre) — `organization_id` est donc NON NULLABLE, contrairement au
 * pattern `roles.organization_id` (rôles système partagés) dont ce référentiel s'inspire pour le
 * reste (label/code/is_active). Une fonction ne contient jamais de permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonctions_rh', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->string('code', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctions_rh');
    }
};
