<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règles de validation par plafond, portées par le RÔLE et par DOMAINE métier (ADR 0021). Premier
 * domaine : `achats` (validation des bons de commande fournisseurs). Une seule ligne par
 * organisation, domaine et rôle. Aucune règle n'est créée ici : sans règle, un rôle ne valide rien
 * (plafond absent = 0) — seul le super administrateur reste sans limite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regles_validation_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('domaine', 40);
            $table->string('role_name');
            $table->decimal('plafond', 14, 2)->nullable();
            $table->boolean('plafond_illimite')->default(false);
            $table->string('perimetre', 30)->default('toutes_agences');
            $table->json('sites')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'domaine', 'role_name'], 'regles_validation_roles_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_validation_roles');
    }
};
