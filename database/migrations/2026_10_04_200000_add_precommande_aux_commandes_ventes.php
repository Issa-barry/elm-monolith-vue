<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Précommandes (ADR 0019, docs/precommandes.md) — schéma uniquement, non destructif : toutes les
 * commandes et tous les encaissements existants restent des ventes et des encaissements ordinaires
 * (`false` / `null`). Garde-fous hasColumn : une migration rejouée sur un environnement déjà migré ne
 * doit jamais échouer sur « Duplicate column ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            if (! Schema::hasColumn('commandes_ventes', 'est_precommande')) {
                $table->boolean('est_precommande')->default(false)->index();
            }
            if (! Schema::hasColumn('commandes_ventes', 'date_remise_prevue')) {
                $table->date('date_remise_prevue')->nullable();
            }
            if (! Schema::hasColumn('commandes_ventes', 'preparation_lancee_at')) {
                $table->timestamp('preparation_lancee_at')->nullable();
            }
            if (! Schema::hasColumn('commandes_ventes', 'preparee_at')) {
                $table->timestamp('preparee_at')->nullable();
            }
            if (! Schema::hasColumn('commandes_ventes', 'remise_at')) {
                $table->timestamp('remise_at')->nullable();
            }
        });

        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            if (! Schema::hasColumn('commande_vente_lignes', 'quantite_preparee')) {
                $table->unsignedInteger('quantite_preparee')->nullable();
            }
        });

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            if (! Schema::hasColumn('encaissements_ventes', 'est_acompte')) {
                $table->boolean('est_acompte')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('encaissements_ventes', function (Blueprint $table) {
            if (Schema::hasColumn('encaissements_ventes', 'est_acompte')) {
                $table->dropColumn('est_acompte');
            }
        });

        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            if (Schema::hasColumn('commande_vente_lignes', 'quantite_preparee')) {
                $table->dropColumn('quantite_preparee');
            }
        });

        Schema::table('commandes_ventes', function (Blueprint $table) {
            if (Schema::hasColumn('commandes_ventes', 'est_precommande')) {
                $table->dropIndex(['est_precommande']);
            }
            foreach (['est_precommande', 'date_remise_prevue', 'preparation_lancee_at', 'preparee_at', 'remise_at'] as $colonne) {
                if (Schema::hasColumn('commandes_ventes', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
