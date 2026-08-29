<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation d'impayés côté client — même principe que Vehicule::derogation_impayes_autorisee/
 * seuil_derogation_impayes (cf. migrations 2026_08_19_000002 et 2026_08_22_000001), désormais
 * pertinent depuis que le client (et non plus le véhicule) porte la facture dès qu'il est
 * sélectionné (cf. SolvabiliteService, décision du 28/08/2026). Toggle + plafond, jamais un seul
 * des deux : DerogationImpayesService::validerCoherence() exige un plafond dès que le toggle est
 * actif, pour les deux entités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('derogation_impayes_autorisee')->default(false)->after('cashback_eligible');
            $table->unsignedInteger('seuil_derogation_impayes')->nullable()->after('derogation_impayes_autorisee');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['derogation_impayes_autorisee', 'seuil_derogation_impayes']);
        });
    }
};
