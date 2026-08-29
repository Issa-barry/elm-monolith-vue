<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origine du prix réellement appliqué à une ligne — 'usine'|'vente'|'externe'|'revendeur'|
 * 'distributeur', cf. App\Enums\PrixOrigine et PrixVenteNatureResolver. Sert l'écran "Prix
 * appliqué" (Ventes/Create.vue, Edit.vue, Show.vue) : des lignes d'une même commande peuvent
 * relever de politiques de prix différentes, donc chaque ligne affiche sa propre origine plutôt
 * qu'un intitulé de colonne unique potentiellement trompeur.
 *
 * Backfill des lignes déjà existantes (antérieures à la tarification par nature de client, donc
 * seules 'usine'/'vente' sont possibles pour elles) à partir de commandes_ventes.
 * mode_tarification_snapshot, déjà figé sur chaque commande — jamais une déduction depuis les
 * prix ACTUELS du produit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->string('prix_origine_snapshot', 20)->nullable()->after('total_ligne');
        });

        DB::table('commande_vente_lignes as cvl')
            ->join('commandes_ventes as cv', 'cv.id', '=', 'cvl.commande_vente_id')
            ->whereNull('cvl.prix_origine_snapshot')
            ->where('cv.mode_tarification_snapshot', 'prix_usine')
            ->update(['cvl.prix_origine_snapshot' => 'usine']);

        DB::table('commande_vente_lignes as cvl')
            ->join('commandes_ventes as cv', 'cv.id', '=', 'cvl.commande_vente_id')
            ->whereNull('cvl.prix_origine_snapshot')
            ->where('cv.mode_tarification_snapshot', 'prix_vente')
            ->update(['cvl.prix_origine_snapshot' => 'vente']);
    }

    public function down(): void
    {
        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->dropColumn('prix_origine_snapshot');
        });
    }
};
