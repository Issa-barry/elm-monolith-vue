<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_enveloppes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Polymorphe léger (CommandeVente / TransfertLogistique...).
            $table->string('source_type', 100);
            $table->ulid('source_id');
            $table->foreignUlid('processus_id')->constrained('commission_processus')->cascadeOnDelete();
            // Polymorphe léger : bénéficiaire direct (livreurs/proprietaires/employes) ou
            // commission_groupes selon le mode de la règle ayant produit l'enveloppe.
            $table->string('cible_type', 40);
            $table->ulid('cible_id');
            $table->decimal('montant_total', 15, 2);
            $table->date('earned_at');
            $table->string('periode_code', 12)->nullable();
            $table->string('statut', 20)->default('creee');
            $table->timestamps();

            // Idempotence par construction : une opération ne produit jamais deux fois
            // l'enveloppe d'une même cible (décision AMOA #6 — une seule enveloppe par
            // couple cible/opération, quel que soit le nombre de règles/catégories internes).
            $table->unique(['source_type', 'source_id', 'cible_type', 'cible_id'], 'comm_enveloppes_idempotence_unique');
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_enveloppes');
    }
};
