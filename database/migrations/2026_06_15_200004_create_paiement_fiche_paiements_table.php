<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_fiche_paiements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('fiche_id')->constrained('paiement_fiches')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->decimal('montant', 15, 2);
            $table->string('mode_paiement', 20);
            // Distingue le wallet Mobile Money utilisé (Orange Money, MTN MoMo, Djomy, ou tout
            // autre) sans jamais coder un opérateur en dur : simple étiquette libre saisie par
            // l'organisation, reprise telle quelle comme suffixe de moyen_paiement
            // ("mobile_money:orange") pour router vers le bon compte via compta_mappings.
            $table->string('moyen_paiement_detail', 30)->nullable();
            $table->date('date_paiement');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fiche_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_fiche_paiements');
    }
};
