<?php

use App\Services\ProprietaireInterneRegularisationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace le numéro de téléphone codé en dur "+224622602693" (ancienne implémentation de
 * Proprietaire::interneParDefautId(), seedé uniquement pour l'organisation historique de
 * démonstration "elm") par une relation explicite par organisation : chaque organisation
 * connaît maintenant directement son propriétaire interne par défaut (véhicules "interne",
 * commissions propriétaire), au lieu de le faire deviner via un numéro/nom particulier.
 * Fixé à l'installation (cf. InstallationService::install()) — jamais dérivé du rôle
 * super_admin/PDG à la volée, pour rester correct même si l'admin change plus tard.
 *
 * Régularisation des organisations déjà installées : cf.
 * ProprietaireInterneRegularisationService (logique extraite pour rester testable et
 * réutilisable en dehors d'une migration one-shot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUlid('proprietaire_interne_id')
                ->nullable()
                ->after('domaine_activite')
                ->constrained('proprietaires')
                ->nullOnDelete();
        });

        app(ProprietaireInterneRegularisationService::class)->regulariserToutes();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_interne_id']);
            $table->dropColumn('proprietaire_interne_id');
        });
    }
};
