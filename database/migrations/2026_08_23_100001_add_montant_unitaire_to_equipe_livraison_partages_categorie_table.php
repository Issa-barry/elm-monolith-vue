<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape additive de la migration % → montant GNF fixe (cf. commande artisan
 * commissions:migrer-partages-livraison) : ajoute les colonnes montant_unitaire/
 * effective_from/effective_to sans toucher aux données existantes ni supprimer
 * part_pourcentage — celle-ci n'est retirée que dans une migration séparée, une
 * fois la conversion vérifiée sur toutes les organisations.
 *
 * part_pourcentage reste NOT NULL : les nouvelles lignes montant_unitaire y
 * écrivent un placeholder 0 (jamais lu par le nouveau code), même convention que
 * capacite_defaut sur type_vehicules — évite une reconstruction de table sous
 * SQLite (suite de tests), qui ne supporte pas nativement l'ALTER COLUMN.
 *
 * Le partage devient versionné (même principe que commission_regles) : une
 * ligne n'est plus mutée en place, elle est close (effective_to renseigné) puis
 * remplacée par une nouvelle ligne active (effective_to NULL) — nécessaire pour
 * qu'une relance de commission historique résolve le partage réellement en
 * vigueur à la date du fait générateur, pas la config actuelle.
 *
 * L'ancienne contrainte unique (equipe_id, categorie_id, livreur_id) est
 * supprimée sans être remplacée par une unique équivalente incluant
 * effective_from : deux versions d'un même tuple créées le même jour
 * porteraient le même effective_from (date, pas timestamp) et se percuteraient.
 * Comme pour commission_regles (même limitation, même choix), "au plus une
 * version active" est garanti par le service applicatif (fermeture puis
 * insertion dans la même transaction), jamais par une contrainte SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->unsignedInteger('montant_unitaire')->nullable()->after('part_pourcentage');
            $table->date('effective_from')->nullable()->after('montant_unitaire');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        // Backfill : toutes les lignes existantes sont actives depuis leur création.
        DB::table('equipe_livraison_partages_categorie')
            ->whereNull('effective_from')
            ->update(['effective_from' => DB::raw('DATE(created_at)')]);

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropUnique('eq_liv_partage_categorie_unique');
            $table->index(['equipe_id', 'categorie_id', 'livreur_id', 'effective_from'], 'eq_liv_partage_categorie_version_idx');
            $table->index(['equipe_id', 'categorie_id', 'effective_to'], 'eq_liv_partage_categorie_actif_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropIndex('eq_liv_partage_categorie_version_idx');
            $table->dropIndex('eq_liv_partage_categorie_actif_idx');
            $table->unique(['equipe_id', 'categorie_id', 'livreur_id'], 'eq_liv_partage_categorie_unique');
            $table->dropColumn(['montant_unitaire', 'effective_from', 'effective_to']);
        });
    }
};
