<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mappings des événements `fiche_reaffectee_sortie` / `fiche_reaffectee_entree` (ADR 0020) pour
 * les organisations existantes. Bootstrap idempotent : il ne crée que ce qui manque et ne modifie
 * jamais un compte ou un mapping déjà configuré.
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
