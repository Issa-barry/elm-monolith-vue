<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mappings de l'événement `paiement_fournisseur` (ADR 0024) pour les organisations existantes :
 * débit 401000 Fournisseurs, journal résolu par le moyen de paiement (CA / MM / BQ), comme les
 * autres paiements. Bootstrap idempotent : ne crée que ce qui manque.
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
