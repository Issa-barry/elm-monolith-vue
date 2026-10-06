<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compte 419100 « Clients — avances et acomptes reçus » et son mapping (acompte de précommande,
 * ADR 0019) pour les organisations existantes. Le bootstrap du plan comptable est idempotent : il ne
 * crée que ce qui manque et ne modifie jamais un compte ou un mapping déjà configuré.
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

    /** Pas de rollback de données : un compte déjà mouvementé ne peut pas être retiré. */
    public function down(): void
    {
        //
    }
};
