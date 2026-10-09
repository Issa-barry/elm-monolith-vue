<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements de factures fournisseurs (ADR 0024, lot 4 du module Achats) : décaissement réel depuis
 * un support de trésorerie de l'agence de la facture, partiel ou total, plusieurs par facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements_fournisseurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('facture_fournisseur_id')->constrained('factures_fournisseurs');
            $table->foreignUlid('fournisseur_id')->constrained('fournisseurs');
            $table->foreignUlid('site_id')->constrained('sites');
            $table->decimal('montant', 14, 2);
            $table->string('mode_paiement', 30);
            $table->string('moyen_paiement_detail', 50)->nullable();
            $table->foreignUlid('compte_tresorerie_id')->constrained('compta_supports_tresorerie');
            $table->string('reference_paiement', 190)->nullable();
            $table->date('date_paiement');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'date_paiement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_fournisseurs');
    }
};
