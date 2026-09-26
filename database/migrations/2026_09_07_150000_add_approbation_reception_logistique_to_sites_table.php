<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation par site au réglage organisation `Parametre::isApprobationReceptionLogistiqueObligatoire()`
 * — même architecture que `Vehicule::derogation_impayes_autorisee`/`seuil_derogation_impayes` et
 * son symétrique `Client` (cf. SolvabiliteService::resoudrePlafondVehicule()/resoudrePlafondClient()),
 * mais sans second flag "dérogation activée" : un booléen NULLABLE n'a pas l'ambiguïté d'un entier
 * (0 vs jamais configuré) — `null` signifie sans détour "aucune dérogation, hérite du réglage
 * organisation", `true`/`false` un override explicite. Résolu par site DESTINATION du transfert
 * (cf. Site::approbationReceptionObligatoireEffective(), TransfertLogistiqueService::avancerStatut()).
 *
 * Tous les sites existants reçoivent `null` (colonne nullable sans défaut) : aucun comportement ne
 * change pour une organisation qui n'a jamais configuré de dérogation — elle continue de suivre
 * intégralement son réglage organisation, exactement comme avant cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('approbation_reception_logistique_obligatoire')->nullable()->after('is_siege_principal');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('approbation_reception_logistique_obligatoire');
        });
    }
};
