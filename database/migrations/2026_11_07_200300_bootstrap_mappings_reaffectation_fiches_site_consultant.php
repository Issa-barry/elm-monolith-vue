<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Changement d'agence payeuse d'une fiche Site ou Consultant (ADR 0061) : mappings
 * `fiche_reaffectee_sortie` / `fiche_reaffectee_entree` des comptes 467170/622300 (sites) et
 * 467180/622400 (consultants) pour les organisations existantes. Bootstrap idempotent : ne crée
 * que ce qui manque, ne modifie aucun compte ni mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bootstrap = app(PlanComptableBootstrapService::class);

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $bootstrap->bootstrap($organizationId);
        }
    }

    /** Pas de rollback de données : un mapping déjà utilisé ne peut pas être retiré. */
    public function down(): void
    {
        //
    }
};
