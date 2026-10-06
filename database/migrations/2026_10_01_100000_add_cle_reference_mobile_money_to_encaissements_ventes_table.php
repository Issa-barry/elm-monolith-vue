<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Unicité des références Mobile Money (règle du 01/10/2026, ADR 0014) : `cle_reference_mobile_money`
 * vaut « organisation|RÉFÉRENCE normalisée » pour un encaissement Mobile Money référencé, null sinon,
 * et porte un index unique.
 *
 * Doublons historiques : seul le plus ancien encaissement de chaque groupe reçoit la clé — la référence
 * reste ainsi bloquée pour toute nouvelle saisie. Les suivants gardent leur référence intacte, sans clé :
 * aucune donnée n'est modifiée ni supprimée, et ils restent signalés par le rapport d'activité et par
 * `encaissements:doublons-reference-mobile-money`.
 */
return new class extends Migration
{
    private const INDEX = 'encaissements_ventes_cle_reference_mobile_money_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'cle_reference_mobile_money')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->string('cle_reference_mobile_money', 255)->nullable()->after('reference_paiement');
            });
        }

        $this->renseignerCles();

        if (! Schema::hasIndex('encaissements_ventes', self::INDEX)) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->unique('cle_reference_mobile_money', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('encaissements_ventes', self::INDEX)) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('encaissements_ventes', 'cle_reference_mobile_money')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->dropColumn('cle_reference_mobile_money');
            });
        }
    }

    private function renseignerCles(): void
    {
        $lignes = DB::table('encaissements_ventes as e')
            ->join('factures_ventes as f', 'f.id', '=', 'e.facture_vente_id')
            ->where('e.mode_paiement', 'mobile_money')
            ->whereNotNull('e.reference_paiement')
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->get(['e.id', 'e.reference_paiement', 'e.cle_reference_mobile_money', 'f.organization_id']);

        $prises = $lignes->pluck('cle_reference_mobile_money')->filter()->flip()->all();
        $doublons = 0;

        foreach ($lignes as $ligne) {
            if ($ligne->cle_reference_mobile_money !== null) {
                continue;
            }

            $reference = mb_strtoupper(trim((string) $ligne->reference_paiement));
            if ($reference === '') {
                continue;
            }

            $cle = $ligne->organization_id.'|'.$reference;
            if (isset($prises[$cle])) {
                $doublons++;

                continue;
            }

            $prises[$cle] = true;
            DB::table('encaissements_ventes')->where('id', $ligne->id)->update(['cle_reference_mobile_money' => $cle]);
        }

        if ($doublons > 0) {
            Log::warning("Références Mobile Money : {$doublons} encaissement(s) historique(s) réutilisent une référence déjà prise — conservés tels quels, détail via `php artisan encaissements:doublons-reference-mobile-money`.");
        }
    }
};
