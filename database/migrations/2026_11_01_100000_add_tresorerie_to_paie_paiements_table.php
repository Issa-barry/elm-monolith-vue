<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de salaire = décaissement réel (ADR 0049) : support d'où sort l'argent, agence débitée,
 * référence de la transaction et auteur. Nullable : les paiements antérieurs ne sont jamais reclassés
 * et gardent leur résolution comptable par compta_mappings (cf. PaieComptabilisationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paie_paiements', function (Blueprint $table) {
            $table->foreignUlid('site_id')->nullable()->after('paie_ligne_id')->constrained('sites')->nullOnDelete();
            $table->string('moyen_paiement_detail', 30)->nullable()->after('mode_paiement');
            $table->string('reference_paiement', 190)->nullable()->after('moyen_paiement_detail');
            $table->foreignUlid('compte_tresorerie_id')
                ->nullable()
                ->after('reference_paiement')
                ->constrained('compta_supports_tresorerie')
                ->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paie_paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('compte_tresorerie_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['moyen_paiement_detail', 'reference_paiement']);
        });
    }
};
