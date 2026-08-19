<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation de contrôle des impayés propre à CE véhicule — un seul champ nullable, pas un
 * booléen "derogation_impayes" séparé (décision produit du 18/08/2026, cf. discussion sur le
 * contrôle des impayés) : NULL = le véhicule hérite du seuil global de l'organisation
 * (Parametre::CLE_VENTES_SEUIL_IMPAYES_MAX), une valeur explicite (y compris 0) REMPLACE ce
 * seuil pour ce véhicule uniquement. Résolu par SolvabiliteService, jamais consulté ailleurs.
 *
 * Tous les véhicules existants reçoivent NULL par défaut (colonne nullable sans valeur par
 * défaut ni backfill) : ils héritent donc naturellement du seuil global dès l'activation du
 * contrôle des impayés, sans dérogation implicite — aucune migration de données nécessaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedInteger('seuil_dette_derogation')->nullable()->after('capacite_bouteilles');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('seuil_dette_derogation');
        });
    }
};
