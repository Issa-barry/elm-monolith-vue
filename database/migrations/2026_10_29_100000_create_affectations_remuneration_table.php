<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectations des primes et avantages aux salariés (lot 3 RH, docs/primes-avantages.md,
 * ADR 0039) et montants saisis chaque mois. Nouvelles tables uniquement : aucune donnée existante
 * n'est touchée. Le salaire de base reste porté par le contrat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectations_remuneration', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employe_id')->constrained('employes')->restrictOnDelete();
            $table->foreignUlid('type_remuneration_id')->constrained('types_remuneration')->restrictOnDelete();
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            // Montant ou taux attribué (selon le mode de calcul du type) ; vides pour un élément
            // « saisi chaque mois », dont le montant vient des saisies mensuelles.
            $table->decimal('montant', 15, 2)->nullable();
            $table->decimal('taux', 5, 2)->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 20)->default('a_valider');
            // Changement de montant : la nouvelle affectation succède à celle-ci à sa date de début.
            $table->foreignUlid('remplace_affectation_id')->nullable()->constrained('affectations_remuneration')->nullOnDelete();
            // Demande de fin d'effet en attente de validation.
            $table->date('date_fin_demandee')->nullable();
            $table->string('motif_fin', 255)->nullable();
            $table->foreignUlid('fin_demandee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fin_demandee_le')->nullable();
            $table->string('motif_annulation', 255)->nullable();
            $table->foreignUlid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->foreignUlid('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'employe_id', 'type_remuneration_id'], 'affect_rem_org_employe_type_idx');
            $table->index(['organization_id', 'statut'], 'affect_rem_org_statut_idx');
            $table->index(['organization_id', 'date_debut', 'date_fin'], 'affect_rem_org_dates_idx');
        });

        Schema::create('affectation_remuneration_saisies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('affectation_id')->constrained('affectations_remuneration')->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedTinyInteger('mois');
            $table->decimal('montant', 15, 2)->nullable();
            $table->decimal('taux', 5, 2)->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 20)->default('a_valider');
            $table->foreignUlid('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();

            // Un seul montant par élément et par mois.
            $table->unique(['affectation_id', 'annee', 'mois'], 'affect_rem_saisie_mois_unique');
            $table->index(['organization_id', 'annee', 'mois'], 'affect_rem_saisie_org_mois_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectation_remuneration_saisies');
        Schema::dropIfExists('affectations_remuneration');
    }
};
