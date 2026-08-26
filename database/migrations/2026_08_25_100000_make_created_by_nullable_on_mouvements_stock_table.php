<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'historique des mouvements de stock ne doit jamais disparaître à la suppression d'un compte
 * utilisateur — corrige un cascadeOnDelete() posé par erreur sur mouvements_stock.created_by
 * (audit stock du 25/08/2026) : contrairement à stock_reservations.created_by (déjà nullOnDelete),
 * ce champ effaçait EN CASCADE toute la trace des mouvements créés par un utilisateur supprimé.
 * MySQL : SQL brut (pas ->change()), ce projet n'a pas doctrine/dbal installé, même convention
 * que 2026_08_23_100004_make_qte_stock_signed_on_variante_stocks_table.php. SQLite (tests,
 * RefreshDatabase) : ->change() y fonctionne nativement pour un simple basculement nullable
 * sans doctrine/dbal (contrairement à MySQL sur ce projet) — vérifié empiriquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            // Tableau de colonnes (pas le nom brut de la contrainte) : seule forme portable
            // entre MySQL et SQLite (utilisé par les tests, RefreshDatabase) — SQLite ne
            // supporte pas dropForeign() par nom de contrainte, seulement par colonne(s).
            $table->dropForeign(['created_by']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('mouvements_stock', function (Blueprint $table) {
                $table->char('created_by', 26)->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE mouvements_stock MODIFY created_by CHAR(26) COLLATE utf8mb4_unicode_ci NULL');
        }

        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        // Les mouvements déjà rendus orphelins (created_by NULL) empêcheraient un rollback qui
        // remettrait NOT NULL — laissé volontairement à une intervention manuelle si un rollback
        // est un jour nécessaire, plutôt que de supprimer silencieusement ces lignes ici.
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('mouvements_stock', function (Blueprint $table) {
                $table->char('created_by', 26)->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE mouvements_stock MODIFY created_by CHAR(26) COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
