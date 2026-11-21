<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tableau de bord et liste des factures filtrent les factures par organisation et date de création :
 * sans cet index, MySQL parcourait toutes les factures de l'organisation (EXPLAIN « ref » sur l'index
 * organisation + référence). Index seul, aucune donnée modifiée ; création en ligne sous MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_ventes', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'factures_ventes_organization_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('factures_ventes', function (Blueprint $table) {
            $table->dropIndex('factures_ventes_organization_id_created_at_index');
        });
    }
};
