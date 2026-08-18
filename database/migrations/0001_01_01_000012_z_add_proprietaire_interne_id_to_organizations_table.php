<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propriétaire économique de l'organisation elle-même — assigné automatiquement aux véhicules
 * "interne" (cf. CategorieVehicule) et aux commissions propriétaire associées, quand aucun
 * tiers n'est explicitement choisi. Relation explicite par organisation, fixée à
 * l'installation (cf. InstallationService::install()) : jamais déduite d'un numéro de
 * téléphone particulier, d'un rôle (super_admin/PDG) ou du premier utilisateur créé, pour
 * rester correcte même si l'admin change plus tard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Pas de ->after('domaine_activite') : cette colonne est ajoutée par une migration
            // datée bien plus tard (2026_08_16_120000_add_domaine_activite_to_organizations_table),
            // qui n'a pas encore tourné à ce stade de l'ordre d'exécution.
            $table->foreignUlid('proprietaire_interne_id')
                ->nullable()
                ->constrained('proprietaires')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_interne_id']);
            $table->dropColumn('proprietaire_interne_id');
        });
    }
};
