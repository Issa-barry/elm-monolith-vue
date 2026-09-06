<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_tarifs_grossiste', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Chaque Grossiste négocie son propre tarif (décision produit du 05/09/2026,
            // révisant le premier jet organisation-wide) — jamais une grille partagée par toute
            // l'organisation. cascadeOnDelete : un tarif n'a de sens que rattaché à son client ET
            // sa catégorie, jamais orphelin.
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUlid('categorie_id')->constrained('categories')->cascadeOnDelete();
            $table->string('mode', 20);
            $table->unsignedBigInteger('prix');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un seul tarif actif par client/catégorie/mode — jamais deux lignes concurrentes
            // pour le même triplet (cf. GrossisteTarifResolver, résolution stricte sans
            // historique de dates contrairement à CommissionRegle).
            $table->unique(['client_id', 'categorie_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_tarifs_grossiste');
    }
};
