<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import de mise à jour des dossiers et contrats RH (docs/import-dossiers-rh.md), sur le modèle de
 * imports_vehicules_maj. `fichier_path` devient NULL quand le fichier déposé est supprimé (après
 * traitement ou abandon) : il contient des salaires et n'est gardé que le temps de la confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_rh', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('fichier_original');
            $table->string('fichier_path')->nullable();

            $table->string('statut', 20)->default('analyse'); // analyse | en_cours | termine | echoue | abandonne

            $table->unsignedInteger('nb_lignes_total')->default(0);
            $table->unsignedInteger('nb_lignes_maj')->default(0);
            $table->unsignedInteger('nb_lignes_inchange')->default(0);
            $table->unsignedInteger('nb_lignes_erreur')->default(0);

            $table->unsignedInteger('nb_employes_mis_a_jour')->nullable();
            $table->unsignedInteger('nb_contrats_crees')->nullable();

            // Aperçu puis rapport final. Les montants de salaire y sont marqués « sensibles » et
            // masqués à l'affichage sans rh-remunerations.read (ImportRhPresenter).
            $table->json('rapport')->nullable();
            $table->text('erreur_technique')->nullable();

            $table->timestamp('analyse_le')->nullable();
            $table->timestamp('demarre_le')->nullable();
            $table->timestamp('termine_le')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports_rh');
    }
};
