<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashback traité comme une commission propre au client (décision produit du 28/08/2026) :
 * chaque client éligible porte son propre montant fixe gagné par pack — il n'existe plus de
 * montant global unique (cf. Parametre::CLE_CASHBACK_MONTANT_GAIN, désormais inerte : la
 * génération lit exclusivement ce champ, jamais plus ce paramètre d'organisation).
 *
 * Nullable : un client non éligible (ou pas encore configuré) n'a pas de montant — jamais 0
 * confondu avec "non configuré" (cf. ProduitVariante::prix_externe et son repli documenté pour
 * la même convention NULL ≠ 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('cashback_montant_par_pack')->nullable()->after('cashback_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('cashback_montant_par_pack');
        });
    }
};
