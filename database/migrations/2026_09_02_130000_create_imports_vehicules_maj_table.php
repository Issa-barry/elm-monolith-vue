<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_vehicules_maj', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('fichier_original');
            $table->string('fichier_path');

            $table->string('statut', 20)->default('analyse'); // analyse | en_cours | termine | echoue

            $table->unsignedInteger('nb_lignes_total')->default(0);
            $table->unsignedInteger('nb_lignes_maj')->default(0);
            $table->unsignedInteger('nb_lignes_inchange')->default(0);
            $table->unsignedInteger('nb_lignes_erreur')->default(0);

            $table->unsignedInteger('nb_vehicules_mis_a_jour')->nullable();

            // Aperçu (à l'analyse) puis rapport final (après exécution) — même convention que
            // imports_produits.rapport / imports_flotte.rapport, structure ré-affichée telle
            // quelle par le frontend.
            $table->json('rapport')->nullable();
            // Message technique sécurisé (jamais le détail SQL brut) — cf.
            // ImportFlotteController::traiter() pour le même principe.
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
        Schema::dropIfExists('imports_vehicules_maj');
    }
};
