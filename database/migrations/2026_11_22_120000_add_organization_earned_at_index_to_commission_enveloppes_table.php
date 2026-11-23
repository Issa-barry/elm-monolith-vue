<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tableau de bord des commissions : la période (« Ce mois », « Mois dernier »…) filtre les
 * commissions sur `earned_at` dans une organisation. Sans cet index, MySQL lit toutes les
 * commissions de l'organisation par (organization_id, statut) puis écarte celles hors période :
 * 146 394 lignes lues pour 26 329 retenues sur le jeu de mesure (docs/tableau-de-bord-commissions.md).
 * Ajout seul, aucune donnée modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_enveloppes', function (Blueprint $table) {
            $table->index(['organization_id', 'earned_at'], 'comm_enveloppes_org_earned_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_enveloppes', function (Blueprint $table) {
            $table->dropIndex('comm_enveloppes_org_earned_at_idx');
        });
    }
};
