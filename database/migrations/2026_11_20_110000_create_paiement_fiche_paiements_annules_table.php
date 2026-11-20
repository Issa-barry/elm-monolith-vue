<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements de fiche annulés (ADR 0069, décision 11) : la ligne de paiement est supprimée et sa
 * pièce contrepassée, mais l'opération garde son identité. Sa clé (operation_id) ne peut plus
 * recréer le paiement, et le détail du paiement reste consultable pour l'audit comptable.
 * Nouvelle table, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_fiche_paiements_annules', function (Blueprint $table) {
            // Identifiant du paiement supprimé : c'est aussi le source_id de sa pièce contrepassée.
            $table->ulid('id')->primary();
            // Sans clé étrangère : une archive n'empêche jamais de supprimer ou régénérer une fiche.
            $table->ulid('fiche_id');
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->decimal('montant', 15, 2);
            $table->string('mode_paiement', 20);
            $table->string('moyen_paiement_detail', 30)->nullable();
            $table->string('reference_paiement', 190)->nullable();
            $table->foreignUlid('compte_tresorerie_id')->nullable()->constrained('compta_supports_tresorerie')->nullOnDelete();
            $table->date('date_paiement');
            $table->text('note')->nullable();
            $table->string('operation_id', 64)->nullable();
            $table->char('operation_empreinte', 64)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paye_le')->nullable();
            $table->foreignUlid('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le');
            $table->timestamps();

            $table->unique(['fiche_id', 'operation_id'], 'pfpa_fiche_operation_unique');
            $table->index(['organization_id', 'operation_id'], 'pfpa_org_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_fiche_paiements_annules');
    }
};
