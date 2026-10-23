<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part du parrain dans le partage d'équipe (cf. docs/parrainage-vehicule.md) : une ligne de partage
 * désigne soit un livreur, soit le parrain du véhicule — sa part est prise sur l'enveloppe Livreur,
 * le total reste égal au barème.
 *
 * Partages préparés d'un brouillon de barème : même ouverture au parrain, plus `processus_id`
 * (partage Distribution d'un véhicule parrainé, préparé dans un brouillon Logistique dont il suit le
 * barème ; vide = processus du brouillon). L'ancienne unicité (brouillon, équipe, catégorie,
 * livreur) ne distinguait pas ces partages : elle est remplacée par un index de recherche,
 * l'unicité étant tenue par l'écriture (suppression puis recréation du groupe). L'index est créé
 * AVANT la suppression : MySQL refuse de retirer l'unique tant qu'il est le seul à couvrir la clé
 * étrangère brouillon_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('equipe_livraison_partages_categorie', 'parrain_id')) {
            Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
                $table->foreignUlid('parrain_id')->nullable()->after('livreur_id')
                    ->constrained('parrains')->restrictOnDelete();
            });

            Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
                $table->ulid('livreur_id')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('commission_bareme_brouillon_partages', 'parrain_id')) {
            Schema::table('commission_bareme_brouillon_partages', function (Blueprint $table) {
                $table->foreignUlid('processus_id')->nullable()->after('categorie_id')
                    ->constrained('commission_processus')->cascadeOnDelete();
                $table->foreignUlid('parrain_id')->nullable()->after('livreur_id')
                    ->constrained('parrains')->cascadeOnDelete();
                $table->index(['brouillon_id', 'equipe_id', 'categorie_id'], 'comm_bareme_brouillon_partage_lookup_idx');
            });

            Schema::table('commission_bareme_brouillon_partages', function (Blueprint $table) {
                $table->dropUnique('comm_bareme_brouillon_partage_unique');
                $table->ulid('livreur_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commission_bareme_brouillon_partages', 'parrain_id')) {
            DB::table('commission_bareme_brouillon_partages')->whereNotNull('parrain_id')->delete();
            DB::table('commission_bareme_brouillon_partages')->whereNotNull('processus_id')->delete();

            Schema::table('commission_bareme_brouillon_partages', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parrain_id');
                $table->dropConstrainedForeignId('processus_id');
                $table->unique(['brouillon_id', 'equipe_id', 'categorie_id', 'livreur_id'], 'comm_bareme_brouillon_partage_unique');
                $table->dropIndex('comm_bareme_brouillon_partage_lookup_idx');
            });
        }

        if (Schema::hasColumn('equipe_livraison_partages_categorie', 'parrain_id')) {
            DB::table('equipe_livraison_partages_categorie')->whereNotNull('parrain_id')->delete();

            Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parrain_id');
            });
        }
    }
};
