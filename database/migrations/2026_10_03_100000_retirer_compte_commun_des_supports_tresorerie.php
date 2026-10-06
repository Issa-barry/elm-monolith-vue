<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait du compte commun (ADR 0016, décision du 2026-10-02) : chaque agence a ses propres comptes
 * et remet ses fonds à la trésorerie principale. Annule les ajouts des migrations
 * 2026_10_02_100000 (`commun`, `compta_support_tresorerie_agences`) et 2026_10_02_200000
 * (`encaissements_ventes.site_detenteur_id`) — sans les supprimer : elles sont déjà dans l'historique
 * de `dev`. `compta_supports_tresorerie.numero` est conservé.
 *
 * Garde-fou : si un compte commun a réellement servi (support commun, ou encaissement dont l'argent
 * est détenu par une autre agence que celle qui l'a encaissé), la migration s'arrête au lieu d'effacer
 * cette information — à traiter à la main avant de relancer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $communs = Schema::hasColumn('compta_supports_tresorerie', 'commun')
            ? DB::table('compta_supports_tresorerie')->where('commun', true)->count()
            : 0;
        $detenusAilleurs = Schema::hasColumn('encaissements_ventes', 'site_detenteur_id')
            ? DB::table('encaissements_ventes')->whereNotNull('site_detenteur_id')->whereColumn('site_detenteur_id', '<>', 'site_encaissement_id')->count()
            : 0;

        if ($communs > 0 || $detenusAilleurs > 0) {
            throw new RuntimeException("Retrait du compte commun interrompu : {$communs} support(s) commun(s) et {$detenusAilleurs} encaissement(s) détenu(s) par une autre agence existent. Les traiter avant de relancer la migration.");
        }

        Schema::dropIfExists('compta_support_tresorerie_agences');

        if (Schema::hasColumn('compta_supports_tresorerie', 'commun')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->dropColumn('commun');
            });
        }

        if (Schema::hasColumn('encaissements_ventes', 'site_detenteur_id')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('site_detenteur_id');
            });
        }
    }

    /** Le compte commun est abandonné : aucune restauration. */
    public function down(): void {}
};
