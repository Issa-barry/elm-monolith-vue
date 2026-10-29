<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date d'effet de la désactivation d'un type (ADR 0039) : plus aucun montant n'est transmis à
 * partir de cette date, même si une affectation était restée ouverte. Colonne nullable : les types
 * existants ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('types_remuneration', 'desactive_au')) {
            Schema::table('types_remuneration', function (Blueprint $table) {
                $table->date('desactive_au')->nullable()->after('statut');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('types_remuneration', 'desactive_au')) {
            Schema::table('types_remuneration', function (Blueprint $table) {
                $table->dropColumn('desactive_au');
            });
        }
    }
};
