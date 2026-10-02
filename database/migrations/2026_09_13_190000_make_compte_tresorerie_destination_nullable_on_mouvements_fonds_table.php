<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le support de trésorerie de destination n'est plus imposé à la création du
 * mouvement de fonds : le destinataire réel des fonds n'est souvent connu
 * qu'au moment de la réception (le siège envoie vers un site, pas vers une
 * caisse précise) — cf. revue produit du 2026-09-13. Il est désormais choisi
 * dans `MouvementFondsService::recevoir()`, jamais figé dans le formulaire de
 * création (`MouvementFondsService::creerBrouillon()` accepte maintenant ce
 * champ en optionnel).
 *
 * MySQL : SQL brut (pas ->change()), ce projet n'a pas doctrine/dbal installé,
 * même convention que 2026_08_25_100000_make_created_by_nullable_on_mouvements_stock_table.php.
 * SQLite (tests, RefreshDatabase) : ->change() y fonctionne nativement pour un
 * simple basculement nullable sans doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->dropForeign(['compte_tresorerie_destination_id']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('mouvements_fonds', function (Blueprint $table) {
                $table->char('compte_tresorerie_destination_id', 26)->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE mouvements_fonds MODIFY compte_tresorerie_destination_id CHAR(26) COLLATE utf8mb4_unicode_ci NULL');
        }

        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->foreign('compte_tresorerie_destination_id')->references('id')->on('compta_supports_tresorerie')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->dropForeign(['compte_tresorerie_destination_id']);
        });

        // Les mouvements déjà rendus orphelins (compte_tresorerie_destination_id NULL,
        // ex: brouillon jamais reçu) empêcheraient un rollback qui remettrait NOT NULL —
        // laissé volontairement à une intervention manuelle si un rollback est un jour
        // nécessaire, plutôt que de supprimer silencieusement ces lignes ici.
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('mouvements_fonds', function (Blueprint $table) {
                $table->char('compte_tresorerie_destination_id', 26)->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE mouvements_fonds MODIFY compte_tresorerie_destination_id CHAR(26) COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->foreign('compte_tresorerie_destination_id')->references('id')->on('compta_supports_tresorerie')->restrictOnDelete();
        });
    }
};
