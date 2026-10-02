<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compte commun à l'encaissement (ADR 0016, lot 0.3).
 *
 * - `compta_supports_tresorerie.numero` : numéro du compte (numéro marchand Mobile Money, numéro
 *   bancaire), affiché à l'encaissement pour que l'agent choisisse le compte sur lequel le client a
 *   réellement payé.
 * - `encaissements_ventes.site_detenteur_id` : agence qui DÉTIENT l'argent reçu, celle du support
 *   choisi — distincte de l'agence qui encaisse (`site_encaissement_id`, traçabilité) quand le
 *   support est un compte commun. Écritures, dette et règlements inter-agences la suivent.
 *   Historique : avant les comptes communs, un encaissement allait toujours sur un support de
 *   l'agence qui encaisse — la détentrice est donc exactement l'agence d'encaissement (à défaut,
 *   celle de la facture, comme le fait le modèle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compta_supports_tresorerie', 'numero')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->string('numero', 50)->nullable()->after('libelle');
            });
        }

        if (! Schema::hasColumn('encaissements_ventes', 'site_detenteur_id')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->foreignUlid('site_detenteur_id')->nullable()->after('site_encaissement_id')->constrained('sites')->nullOnDelete();
            });
        }

        DB::table('encaissements_ventes')
            ->whereNull('site_detenteur_id')
            ->whereNotNull('site_encaissement_id')
            ->update(['site_detenteur_id' => DB::raw('site_encaissement_id')]);

        DB::statement('UPDATE encaissements_ventes SET site_detenteur_id = (SELECT factures_ventes.site_id FROM factures_ventes WHERE factures_ventes.id = encaissements_ventes.facture_vente_id) WHERE site_detenteur_id IS NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('encaissements_ventes', 'site_detenteur_id')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('site_detenteur_id');
            });
        }

        if (Schema::hasColumn('compta_supports_tresorerie', 'numero')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->dropColumn('numero');
            });
        }
    }
};
