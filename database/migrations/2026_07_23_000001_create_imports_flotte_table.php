<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_flotte', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('fichier_original');
            $table->string('fichier_path');

            $table->string('statut', 20)->default('analyse'); // analyse | en_cours | termine | echoue

            $table->unsignedInteger('nb_lignes_total')->default(0);
            $table->unsignedInteger('nb_groupes_valides')->default(0);
            $table->unsignedInteger('nb_groupes_erreur')->default(0);

            $table->unsignedInteger('nb_proprietaires_crees')->nullable();
            $table->unsignedInteger('nb_vehicules_crees')->nullable();
            $table->unsignedInteger('nb_livreurs_crees')->nullable();
            $table->unsignedInteger('nb_equipes_creees')->nullable();

            // Résultat détaillé de l'analyse (aperçu) puis de l'exécution (rapport final) —
            // structure : liste de groupes {immatriculation, lignes, statut, erreurs[]}.
            $table->json('rapport')->nullable();

            $table->timestamp('analyse_le')->nullable();
            $table->timestamp('demarre_le')->nullable();
            $table->timestamp('termine_le')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports_flotte');
    }
};
