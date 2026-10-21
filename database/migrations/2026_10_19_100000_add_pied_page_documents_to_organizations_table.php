<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pied de page officiel des factures et bons (raison sociale, adresse, RCCM, NIF, contacts), propre
 * à chaque organisation et saisi dans Paramètres → Organisation (ADR 0033). Aucune valeur initiale :
 * une organisation sans texte n'a pas ce bloc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'pied_page_documents')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->text('pied_page_documents')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'pied_page_documents')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('pied_page_documents');
            });
        }
    }
};
