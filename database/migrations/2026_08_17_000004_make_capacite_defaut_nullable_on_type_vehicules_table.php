<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TypeVehicule redevient une classification pure (nom du type de véhicule), sans porter aucune
 * capacité — décision produit du 17/08/2026 : plus aucun héritage de capacité depuis le type,
 * la capacité de chargement appartient exclusivement au véhicule, par catégorie du catalogue
 * produit (cf. vehicule_capacites / Categorie). capacite_defaut/capacite_defaut_bouteilles/
 * unite_capacite restent en base (colonnes désormais mortes, plus lues par aucun code) pour ne
 * pas complexifier cette migration avec une suppression de colonnes — nullable pour ne plus
 * jamais être exigées applicativement. SQL brut (pas ->change()) : ce projet n'a pas
 * doctrine/dbal installé.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (utilisé par la suite de tests automatisés) n'a pas d'ALTER COLUMN — changer
        // la nullabilité y exigerait une reconstruction complète de table (doctrine/dbal, non
        // installé). Sans effet pratique sur ce driver : capacite_defaut reste une colonne
        // morte que chaque écrivain restant (factory/seeder/contrôleur) alimente avec un
        // placeholder (0), donc la nullabilité n'est pas requise pour que l'app fonctionne.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE type_vehicules MODIFY capacite_defaut INT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE type_vehicules MODIFY capacite_defaut INT NOT NULL');
    }
};
