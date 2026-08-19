<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace Vehicule::seuil_dette_derogation (montant libre par véhicule, migration
 * 2026_08_18_000005) par un simple booléen — décision produit du 19/08/2026 : le montant de
 * dérogation n'est plus saisi véhicule par véhicule, il est désormais porté par le type de
 * véhicule (TypeVehicule::seuil_derogation_impayes, cf. migration 2026_08_19_000001). Le
 * véhicule ne fait plus que décider s'il a le droit d'utiliser le plafond de son type — cf.
 * SolvabiliteService::seuilApplicableVehicule().
 *
 * Tous les véhicules existants reçoivent `false` (colonne booléenne avec défaut) : aucune
 * dérogation n'est accordée implicitement, qu'ils aient eu ou non un ancien
 * seuil_dette_derogation renseigné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('seuil_dette_derogation');
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->boolean('derogation_impayes_autorisee')->default(false)->after('capacite_bouteilles');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('derogation_impayes_autorisee');
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedInteger('seuil_dette_derogation')->nullable()->after('capacite_bouteilles');
        });
    }
};
