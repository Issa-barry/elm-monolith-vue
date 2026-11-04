<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dépense imputée à un parrain (PARRAIN-026) : mapping `depense_avance_tiers_validee` /
 * `avance_tiers_parrain` → 467220 « Parrains — avances à récupérer » pour les organisations
 * existantes. Bootstrap idempotent : ne crée que ce qui manque, ne modifie aucun compte ni mapping.
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
