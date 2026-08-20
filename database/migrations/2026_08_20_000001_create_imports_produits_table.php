<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_produits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('fichier_original');
            $table->string('fichier_path');
            // sha256 du contenu du fichier — détection d'un même fichier déjà importé
            // (cf. ImportProduitsParser) et contrôle d'intégrité entre l'analyse et la
            // confirmation (cf. ImportProduitsExecutor).
            $table->string('fichier_hash', 64)->nullable();

            $table->string('statut', 20)->default('analyse'); // analyse | en_cours | termine | echoue

            $table->unsignedInteger('nb_lignes_total')->default(0);
            $table->unsignedInteger('nb_lignes_creation')->default(0);
            $table->unsignedInteger('nb_lignes_mise_a_jour')->default(0);
            $table->unsignedInteger('nb_lignes_inchange')->default(0);
            $table->unsignedInteger('nb_lignes_erreur')->default(0);

            $table->unsignedInteger('nb_produits_crees')->nullable();
            $table->unsignedInteger('nb_produits_mis_a_jour')->nullable();

            // Aperçu (à l'analyse) puis rapport final (après exécution) — même convention que
            // imports_flotte.rapport, structure ré-affichée telle quelle par le frontend.
            $table->json('rapport')->nullable();
            // Message technique sécurisé (jamais le détail SQL brut) — cf.
            // ImportFlotteController::traiter() pour le même principe.
            $table->text('erreur_technique')->nullable();

            $table->timestamp('analyse_le')->nullable();
            $table->timestamp('demarre_le')->nullable();
            $table->timestamp('termine_le')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'fichier_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports_produits');
    }
};
