<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de fiche = décaissement réel (ADR 0009) : support de trésorerie d'où sort l'argent
 * (caisse dédiée du payeur pour les espèces, compte Mobile Money/banque sinon) et référence de la
 * transaction. Nullable : les paiements antérieurs ne sont jamais reclassés et gardent leur
 * résolution comptable par compta_mappings (cf. FicheComptabilisationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            if (! Schema::hasColumn('paiement_fiche_paiements', 'compte_tresorerie_id')) {
                $table->foreignUlid('compte_tresorerie_id')
                    ->nullable()
                    ->after('moyen_paiement_detail')
                    ->constrained('compta_supports_tresorerie')
                    ->restrictOnDelete();
            }
            if (! Schema::hasColumn('paiement_fiche_paiements', 'reference_paiement')) {
                $table->string('reference_paiement', 190)->nullable()->after('moyen_paiement_detail');
            }
        });
    }

    public function down(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            if (Schema::hasColumn('paiement_fiche_paiements', 'compte_tresorerie_id')) {
                $table->dropConstrainedForeignId('compte_tresorerie_id');
            }
            if (Schema::hasColumn('paiement_fiche_paiements', 'reference_paiement')) {
                $table->dropColumn('reference_paiement');
            }
        });
    }
};
