<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personnes physiques qui représentent une entreprise tierce (ADR 0050). Un interlocuteur n'est
 * jamais bénéficiaire d'une commission : les parts restent attribuées au prestataire de
 * l'entreprise. Un interlocuteur n'est jamais supprimé : on le désactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprise_interlocuteurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('entreprise_tierce_id')->constrained('entreprises_tierces')->restrictOnDelete();
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('acces_commissions')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Nom explicite : le nom généré dépasse la limite de 64 caractères de MySQL.
            $table->unique(['entreprise_tierce_id', 'personne_id'], 'entreprise_interlocuteurs_entreprise_personne_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprise_interlocuteurs');
    }
};
