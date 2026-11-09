<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 7c (ADR 0060, D12 à D14) — nature du client enregistrée sur chaque
 * nouvelle commande, au moment de sa création, pour que l'historique commercial et les commissions
 * ne changent plus quand la nature d'un client change. Colonne facultative, sans reprise (D13) : les
 * commandes déjà enregistrées restent vides et gardent exactement le comportement actuel (repli sur
 * la nature actuelle du client, CommandeVente::natureClient()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->string('nature_client', 30)->nullable()->after('nature_operation');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn('nature_client');
        });
    }
};
