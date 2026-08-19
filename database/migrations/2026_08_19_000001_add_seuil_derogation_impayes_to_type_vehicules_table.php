<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plafond de dérogation au contrôle des impayés, propre à CHAQUE type de véhicule (Tricycle,
 * Camion...) — décision produit du 19/08/2026, en correction de la migration
 * 2026_08_18_000005 (seuil libre par véhicule, abandonné). NULL = aucun seuil dérogatoire
 * configuré pour ce type : un véhicule de ce type ne peut alors PAS activer
 * Vehicule::derogation_impayes_autorisee (cf. VehiculeController::ensureDerogationCoherente()),
 * jamais interprété comme "illimité" (cf. SolvabiliteService::seuilApplicableVehicule(), filet
 * de sécurité qui retombe sur le seuil global si ce cas survient malgré tout).
 *
 * Tous les types existants reçoivent NULL par défaut (colonne nullable sans backfill) : aucune
 * dérogation n'est accordée implicitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->unsignedInteger('seuil_derogation_impayes')->nullable()->after('categorie_tarifaire');
        });
    }

    public function down(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->dropColumn('seuil_derogation_impayes');
        });
    }
};
