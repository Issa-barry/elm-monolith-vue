<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet de distinguer le wallet Mobile Money utilisé (Orange Money, MTN MoMo,
 * Djomy, ou tout autre) sans jamais coder un opérateur en dur : c'est une
 * simple étiquette libre saisie/choisie par l'organisation, reprise telle
 * quelle comme suffixe de moyen_paiement ("mobile_money:orange") pour router
 * vers le bon compte de trésorerie via compte_mappings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->string('moyen_paiement_detail', 30)->nullable()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->dropColumn('moyen_paiement_detail');
        });
    }
};
