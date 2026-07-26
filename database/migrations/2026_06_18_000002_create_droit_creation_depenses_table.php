<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garde hasTable() : sur les environnements déjà déployés avant la
 * consolidation des migrations, la table existe déjà (créée par l'ancienne
 * migration 2026_06_18_000003_revert_to_module_specific_droits, supprimée
 * depuis). Sans cette garde, `migrate` échoue avec "table already exists"
 * car ce nouveau nom de fichier n'est pas encore dans `migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('droit_creation_depenses')) {
            return;
        }

        Schema::create('droit_creation_depenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            // 'toutes_agences' | 'agences_selectionnees'
            $table->string('perimetre')->default('toutes_agences');
            // JSON array of site ULIDs, null when perimetre = 'toutes_agences'
            $table->json('sites')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->boolean('peut_valider')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'role_name']);
            $table->index(['organization_id', 'is_actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('droit_creation_depenses');
    }
};
