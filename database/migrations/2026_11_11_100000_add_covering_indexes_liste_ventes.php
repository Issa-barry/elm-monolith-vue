<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Totaux de la liste Ventes (App\Support\Ventes\ListeVentes) : pour chaque vente filtrée, la facture
 * et la somme de ses encaissements sont lues dans l'index seul, sans relire les lignes (EXPLAIN :
 * « Covering index lookup »). Index seuls, aucune donnée modifiée ; création en ligne sous MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_ventes', function (Blueprint $table) {
            $table->index(['commande_vente_id', 'deleted_at', 'statut_facture', 'montant_net', 'montant_rembourse'], 'fv_commande_montants_index');
        });

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->index(['facture_vente_id', 'montant'], 'ev_facture_montant_index');
        });
    }

    /**
     * MySQL a pu retirer de lui-même l'index simple de la clé étrangère, devenu redondant avec ces
     * index : il est recréé avant de les supprimer, sinon la clé étrangère bloque la suppression.
     */
    public function down(): void
    {
        foreach ([
            ['encaissements_ventes', 'facture_vente_id', 'encaissements_ventes_facture_vente_id_foreign', 'ev_facture_montant_index'],
            ['factures_ventes', 'commande_vente_id', 'factures_ventes_commande_vente_id_foreign', 'fv_commande_montants_index'],
        ] as [$table, $colonne, $indexCleEtrangere, $indexCouvrant]) {
            Schema::table($table, function (Blueprint $t) use ($table, $colonne, $indexCleEtrangere, $indexCouvrant) {
                if (! Schema::hasIndex($table, $indexCleEtrangere)) {
                    $t->index($colonne, $indexCleEtrangere);
                }
                $t->dropIndex($indexCouvrant);
            });
        }
    }
};
