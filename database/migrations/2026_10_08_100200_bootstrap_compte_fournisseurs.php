<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compte 401000 « Fournisseurs » et mapping `facture_fournisseur_validee / fournisseur` (journal AC)
 * pour les organisations existantes (ADR 0022). Bootstrap idempotent : ne crée que ce qui manque,
 * ne modifie jamais un compte ou un mapping déjà configuré. Les comptes d'achat (charge) et de TVA
 * déductible ne sont PAS créés : ils attendent la validation du comptable.
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
