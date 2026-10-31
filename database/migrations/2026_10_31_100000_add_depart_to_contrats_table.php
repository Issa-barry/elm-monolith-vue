<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Départ d'un salarié (docs/rh-depart-salarie.md) : le contrat actif est terminé à la date de
 * sortie. Sa date de fin d'origine est conservée pour qu'une annulation du départ le rétablisse tel
 * qu'il était. Colonnes nouvelles, valeurs par défaut neutres : aucun contrat existant ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            if (! Schema::hasColumn('contrats', 'termine_par_depart')) {
                $table->boolean('termine_par_depart')->default(false)->after('statut_contrat');
            }
            if (! Schema::hasColumn('contrats', 'date_fin_avant_depart')) {
                $table->date('date_fin_avant_depart')->nullable()->after('termine_par_depart');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            $table->dropColumn(['termine_par_depart', 'date_fin_avant_depart']);
        });
    }
};
