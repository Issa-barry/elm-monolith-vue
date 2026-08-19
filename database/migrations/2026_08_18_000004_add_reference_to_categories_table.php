<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ajoute une référence machine stable, indépendante du libellé affiché (`nom`, librement
 * renommable par l'organisation) — même pattern que ProduitType::code (cf.
 * ProduitType::genererCodeUnique()), en MAJUSCULES pour lecture directe dans un fichier
 * d'import (ex: colonne "capacite_BOUTEILLE_EAU"). Sert de référence robuste pour l'import
 * flotte (ImportFlotteParser::resoudreCategoriesCapacite()) : cibler une Categorie par
 * `reference` plutôt que par correspondance de `nom` survit à un renommage, contrairement à la
 * convention de nom fragile qu'elle remplace.
 *
 * Backfill en DB brute (pas via le modèle Eloquent, cf. convention déjà suivie par
 * 2026_08_18_000003_backfill_prix_usine_tricycle) : même algorithme que
 * ProduitType::genererCodeUnique() (slug du nom, suffixe _2/_3... en cas de collision), scopé
 * par organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('reference', 80)->nullable()->after('nom');
        });

        $categories = DB::table('categories')
            ->orderBy('organization_id')
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'nom']);

        $referencesUtiliseesParOrg = [];

        foreach ($categories as $categorie) {
            $base = Str::upper(Str::slug($categorie->nom, '_')) ?: 'CATEGORIE';
            $reference = $base;
            $i = 2;

            while (in_array($reference, $referencesUtiliseesParOrg[$categorie->organization_id] ?? [], true)) {
                $reference = "{$base}_{$i}";
                $i++;
            }

            $referencesUtiliseesParOrg[$categorie->organization_id][] = $reference;

            DB::table('categories')->where('id', $categorie->id)->update(['reference' => $reference]);
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->dropColumn('reference');
        });
    }
};
