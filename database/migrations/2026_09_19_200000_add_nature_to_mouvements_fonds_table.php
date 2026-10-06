<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nature d'un mouvement de fonds (chantier caisses dédiées, phase 3) :
 *  - `inter_sites`      : mouvement entre deux agences (remise au siège, financement) — tout
 *                         l'existant, origine et destination toujours différentes ;
 *  - `interne_caisses`  : versement d'une caisse dédiée à un agent vers une caisse de l'agence,
 *                         au sein d'un même site.
 *
 * Le défaut `inter_sites` est la vérité historique, pas un choix de comportement : jusqu'ici un
 * mouvement dont l'origine et la destination sont le même site était refusé, donc chaque ligne
 * existante est entre deux agences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->string('nature', 20)->default('inter_sites')->after('reference');
            $table->index(['organization_id', 'nature'], 'mouvements_fonds_org_nature_index');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->dropIndex('mouvements_fonds_org_nature_index');
            $table->dropColumn('nature');
        });
    }
};
