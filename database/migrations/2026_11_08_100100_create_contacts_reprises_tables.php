<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 5 (ADR 0060) — journal des reprises de données. Chaque exécution
 * appliquée (ou annulation) reçoit un identifiant ; chaque propriétaire traité garde ses valeurs
 * d'avant et ce que l'exécution a créé ou modifié, pour pouvoir annuler les rattachements de
 * cette exécution sans supprimer ni entreprise ni interlocuteur. Une simulation n'écrit rien ici.
 * Identifiants et indicateurs seulement : aucune donnée personnelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts_reprises', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('commande', 100);
            // application | annulation
            $table->string('mode', 20);
            $table->foreignUlid('reprise_annulee_id')->nullable()->constrained('contacts_reprises')->restrictOnDelete();
            $table->foreignUlid('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('rapport')->nullable();
            $table->timestamp('terminee_le')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'commande', 'created_at'], 'contacts_reprises_commande_index');
        });

        Schema::create('contacts_reprise_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('contacts_reprise_id')->constrained('contacts_reprises')->restrictOnDelete();
            $table->foreignUlid('proprietaire_id')->constrained('proprietaires')->restrictOnDelete();
            $table->foreignUlid('entreprise_tierce_id')->constrained('entreprises_tierces')->restrictOnDelete();
            $table->boolean('entreprise_creee')->default(false);
            $table->foreignUlid('interlocuteur_id')->nullable()->constrained('entreprise_interlocuteurs')->restrictOnDelete();
            // absent | actif | inactif : état du lien du contact avant l'exécution
            $table->string('interlocuteur_etat_avant', 10);
            $table->boolean('principal_pose')->default(false);
            $table->json('valeurs_avant');
            // annulee | conflit
            $table->string('annulation_statut', 10)->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            // Nom explicite : celui généré dépasse la limite MySQL de 64 caractères.
            $table->index(['contacts_reprise_id', 'annulation_statut'], 'contacts_reprise_lignes_statut_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_reprise_lignes');
        Schema::dropIfExists('contacts_reprises');
    }
};
