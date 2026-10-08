<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règles portées par le RÔLE et par DOMAINE métier (ADR 0021) : périmètre d'agences et plafond de
 * validation. Premier domaine : `achats`, où le périmètre (« Peut acheter pour ») gouverne la
 * création, la lecture et la validation des bons de commande. Une seule ligne par organisation,
 * domaine et rôle. Sans règle, un rôle n'a aucun accès, super administrateur compris ; les règles
 * de départ sont créées par 2026_10_07_200400_provisionner_regles_achats_par_defaut.
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
