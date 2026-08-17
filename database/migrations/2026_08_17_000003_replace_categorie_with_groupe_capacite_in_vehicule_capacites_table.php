<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vehicule_capacites doit regrouper par groupe de capacité (Sachets, Bouteilles...), pas par
 * Categorie catalogue (Produits finis, Matières premières...) — voir migration
 * create_groupes_capacite_table. Remplacement propre (pas de backfill : la fonctionnalité de
 * capacité par catégorie vient d'être introduite le 10/08/2026, aucune organisation n'a encore
 * de données réelles dessus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicule_capacites', function (Blueprint $table) {
            // La contrainte FK de vehicule_id ne repose sur aucun index dédié — elle a toujours
            // été supportée par l'index unique composite (vehicule_id, categorie_id) créé en
            // même temps qu'elle. Le supprimer sans alternative bloque MySQL ("Cannot drop
            // index ... needed in a foreign key constraint", erreur 1553) : cet index temporaire
            // prend le relais le temps de la migration, retiré une fois le nouvel index composite
            // (vehicule_id, groupe_capacite_id) en place plus bas.
            $table->index('vehicule_id', 'vehicule_capacites_vehicule_id_temp_index');
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->dropForeign(['categorie_id']);
            $table->dropUnique(['vehicule_id', 'categorie_id']);
            $table->dropColumn('categorie_id');
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->foreignUlid('groupe_capacite_id')->after('vehicule_id')
                ->constrained('groupes_capacite')->cascadeOnDelete();
            $table->unique(['vehicule_id', 'groupe_capacite_id']);
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->dropIndex('vehicule_capacites_vehicule_id_temp_index');
        });
    }

    public function down(): void
    {
        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->index('vehicule_id', 'vehicule_capacites_vehicule_id_temp_index');
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->dropForeign(['groupe_capacite_id']);
            $table->dropUnique(['vehicule_id', 'groupe_capacite_id']);
            $table->dropColumn('groupe_capacite_id');
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->foreignUlid('categorie_id')->after('vehicule_id')
                ->constrained('categories')->cascadeOnDelete();
            $table->unique(['vehicule_id', 'categorie_id']);
        });

        Schema::table('vehicule_capacites', function (Blueprint $table) {
            $table->dropIndex('vehicule_capacites_vehicule_id_temp_index');
        });
    }
};
