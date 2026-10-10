<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agence payeuse d'un bon de commande, distincte de l'agence de livraison (décision du 10/10/2026,
 * révise l'ADR 0021) : `site_id` reste l'agence qui réceptionne et reçoit le stock, `site_payeur_id`
 * porte la facture, la dette et le paiement (achats centralisés par la trésorerie principale).
 *
 * Non destructive : les bons existants gardent leur comportement, leur agence payeuse est
 * initialisée à leur agence de livraison (et le nom figé repris du nom de l'agence figé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_achats', function (Blueprint $table) {
            if (! Schema::hasColumn('commandes_achats', 'site_payeur_id')) {
                $table->foreignUlid('site_payeur_id')->nullable()->after('site_id')->constrained('sites')->nullOnDelete();
            }
            if (! Schema::hasColumn('commandes_achats', 'site_payeur_nom_snapshot')) {
                $table->string('site_payeur_nom_snapshot')->nullable()->after('site_nom_snapshot');
            }
        });

        DB::table('commandes_achats')->whereNull('site_payeur_id')->whereNotNull('site_id')
            ->update(['site_payeur_id' => DB::raw('site_id')]);
        DB::table('commandes_achats')->whereNull('site_payeur_nom_snapshot')->whereNotNull('site_nom_snapshot')
            ->update(['site_payeur_nom_snapshot' => DB::raw('site_nom_snapshot')]);
    }

    public function down(): void
    {
        Schema::table('commandes_achats', function (Blueprint $table) {
            if (Schema::hasColumn('commandes_achats', 'site_payeur_id')) {
                $table->dropConstrainedForeignId('site_payeur_id');
            }
            if (Schema::hasColumn('commandes_achats', 'site_payeur_nom_snapshot')) {
                $table->dropColumn('site_payeur_nom_snapshot');
            }
        });
    }
};
