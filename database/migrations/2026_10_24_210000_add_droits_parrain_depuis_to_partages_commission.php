<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARRAIN-024 : date de début des droits du parrain en attente, sur la ligne du parrain d'un partage
 * à plusieurs livreurs resté « À faire » (onglet Parrain, date passée). La répartition faite ensuite
 * dans l'onglet Équipe s'applique depuis cette date, avec le recalcul de PARRAIN-023. Vide pour
 * toutes les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipe_livraison_partages_categorie', 'droits_parrain_depuis')) {
            return;
        }

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->date('droits_parrain_depuis')->nullable()->after('part_parrain_confirmee_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('equipe_livraison_partages_categorie', 'droits_parrain_depuis')) {
            return;
        }

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropColumn('droits_parrain_depuis');
        });
    }
};
