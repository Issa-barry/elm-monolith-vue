<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support de trésorerie réellement choisi pour un encaissement Mobile Money, virement ou chèque :
 * l'écriture débite SON compte (cf. VenteComptabilisationService). Nullable : les espèces restent
 * routées vers la caisse dédiée de l'auteur (CaisseAgentResolver) et l'historique n'est jamais
 * reclassé (ADR 0001) — un encaissement antérieur garde sa résolution par compta_mappings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('encaissements_ventes', 'compte_tresorerie_id')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->foreignUlid('compte_tresorerie_id')
                ->nullable()
                ->after('operateur_mobile_money')
                ->constrained('compta_supports_tresorerie')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'compte_tresorerie_id')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compte_tresorerie_id');
        });
    }
};
