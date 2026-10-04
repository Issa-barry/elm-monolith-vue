<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mappings des événements `acompte_precommande_impute` (419100 → 411000 à la remise) et
 * `remboursement_client` (sortie de trésorerie vers un client), ADR 0019 lot 2, pour les
 * organisations existantes. Bootstrap idempotent : il ne crée que ce qui manque et ne modifie jamais
 * un compte ou un mapping déjà configuré.
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
