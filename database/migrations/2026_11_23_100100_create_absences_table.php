<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absences des salariés (docs/rh-absences.md, ADR 0070). Nouvelle table uniquement : aucune donnée
 * existante n'est touchée. Une absence n'est jamais supprimée : l'annulation passe `statut` à
 * `annulee`. La durée n'est pas stockée : elle se déduit des dates (jours calendaires inclus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employe_id')->constrained('employes')->restrictOnDelete();
            $table->foreignUlid('motif_absence_id')->constrained('motifs_absence')->restrictOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->text('commentaire')->nullable();
            // Justificatif facultatif, sur le disque privé `absences_rh` (jamais d'URL publique).
            $table->string('justificatif_path')->nullable();
            $table->string('justificatif_nom_original')->nullable();
            $table->string('justificatif_mime_type', 100)->nullable();
            $table->unsignedInteger('justificatif_taille')->nullable();
            $table->string('statut', 20)->default('enregistree');
            $table->foreignUlid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le')->nullable();
            $table->string('motif_annulation', 255)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'date_debut']);
            $table->index(['employe_id', 'statut', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
