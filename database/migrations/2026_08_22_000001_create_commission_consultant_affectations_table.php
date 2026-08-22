<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Désigne QUEL prestataire (type Consultant) est actuellement le bénéficiaire de la cible de
 * commission "consultant" pour une organisation — jamais un prestataire codé en dur. Même
 * principe de versionnement que commission_regles (remplace_affectation_id, effective_from/to,
 * statut) : une désignation n'est jamais modifiée en place, elle est close puis remplacée, pour
 * que les commissions déjà générées (CommissionEnveloppePart.beneficiaire_id, snapshotté à la
 * génération) ne soient jamais affectées rétroactivement par un changement de consultant.
 *
 * Pas de contrainte SQL "une seule ligne active par organisation" (MySQL ne supporte pas les
 * index uniques partiels) — garantie applicative, cf. employe_affectations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_consultant_affectations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete : un prestataire ayant déjà été désigné bénéficiaire ne doit
            // jamais pouvoir être supprimé silencieusement (cf. Prestataire::SoftDeletes,
            // suppression réelle de toute façon jamais attendue ici).
            $table->foreignUlid('prestataire_id')->constrained('prestataires')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Nom de contrainte explicite et raccourci : le nom auto-généré par Laravel
            // ("commission_consultant_affectations_remplace_affectation_id_foreign", 68
            // caractères) dépasse la limite MySQL de 64 caractères pour un identifiant
            // (SQLSTATE[42000] 1059), même style d'abréviation que l'index ci-dessous.
            $table->foreignUlid('remplace_affectation_id')->nullable()
                ->constrained('commission_consultant_affectations', 'id', 'comm_consultant_aff_remplace_fk')
                ->nullOnDelete();
            $table->string('statut', 20)->default('active');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'statut'], 'comm_consultant_aff_org_statut_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_consultant_affectations');
    }
};
