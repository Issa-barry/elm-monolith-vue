<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unicité du numéro de facture du fournisseur GARANTIE EN BASE (ADR 0022), même entre deux saisies
 * simultanées : clé technique = numéro tant que la facture n'est pas annulée, NULL une fois annulée
 * (un numéro annulé peut être ressaisi ; plusieurs NULL sont permis par l'index unique). Même principe
 * que l'unicité des références Mobile Money (ADR 0014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            if (! Schema::hasColumn('factures_fournisseurs', 'cle_numero_unique')) {
                $table->string('cle_numero_unique', 100)->nullable()->after('numero_facture_fournisseur');
                $table->unique(['organization_id', 'fournisseur_id', 'cle_numero_unique'], 'factures_fournisseurs_numero_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->dropUnique('factures_fournisseurs_numero_unique');
            $table->dropColumn('cle_numero_unique');
        });
    }
};
