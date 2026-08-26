<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation FIFO d'un paiement de fiche (paiement_fiche_paiements — table
     * PARTAGÉE entre Legacy et V2, cf. décision AMOA : une seule chaîne de
     * paiement, jamais deux circuits pour V2) sur les CommissionEnveloppePart
     * réellement soldées par ce paiement. Sans cette table, un paiement de
     * fiche V2 resterait invisible depuis les écrans "Commission vente" /
     * "Commission propriétaire" (qui lisent commission_enveloppe_parts.montant_verse) —
     * cf. PaiementFichePaiementController::store().
     */
    public function up(): void
    {
        Schema::create('commission_enveloppe_paiement_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('paiement_id');
            $table->foreign('paiement_id', 'cepi_paiement_id_foreign')
                ->references('id')->on('paiement_fiche_paiements')->cascadeOnDelete();
            $table->foreignUlid('commission_enveloppe_part_id');
            $table->foreign('commission_enveloppe_part_id', 'cepi_part_id_foreign')
                ->references('id')->on('commission_enveloppe_parts')->cascadeOnDelete();
            $table->decimal('amount_allocated', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_enveloppe_paiement_items');
    }
};
