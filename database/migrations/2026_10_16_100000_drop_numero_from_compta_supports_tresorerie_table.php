<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0030 — le numéro d'un support de trésorerie (numéro de portefeuille Mobile Money, numéro
 * marchand, numéro bancaire) est une donnée sensible que l'application ne demande ni ne conserve
 * plus. La colonne est supprimée AVEC ses données, banques comprises (décision du propriétaire du
 * produit, 2026-10-16) : irréversible, le rollback ne recrée qu'une colonne vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('compta_supports_tresorerie', 'numero')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->dropColumn('numero');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('compta_supports_tresorerie', 'numero')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->string('numero', 50)->nullable()->after('libelle');
            });
        }
    }
};
