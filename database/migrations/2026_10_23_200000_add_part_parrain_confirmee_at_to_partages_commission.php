<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARRAIN-022 : date à laquelle une personne a confirmé la part NULLE du parrain sur un partage
 * (ligne du parrain seulement). Vide pour toutes les lignes existantes : une ligne à 0 GNF créée par
 * le système (ajout ou remplacement d'un parrain, reprise au déploiement) reste « à répartir » —
 * aucune décision n'est attribuée rétroactivement. Une part positive vaut toujours décision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipe_livraison_partages_categorie', 'part_parrain_confirmee_at')) {
            return;
        }

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->timestamp('part_parrain_confirmee_at')->nullable()->after('montant_unitaire');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('equipe_livraison_partages_categorie', 'part_parrain_confirmee_at')) {
            return;
        }

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropColumn('part_parrain_confirmee_at');
        });
    }
};
