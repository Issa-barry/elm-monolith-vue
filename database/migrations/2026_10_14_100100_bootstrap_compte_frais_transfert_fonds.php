<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compte de charge 631800 « Frais de transfert de fonds » et mapping de l'événement
 * `frais_transfert_fonds` (ADR 0027, lot 2) pour les organisations existantes. Bootstrap
 * idempotent : ne crée que ce qui manque, ne modifie aucun paramétrage déjà en place.
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

    /** Pas de rollback de données : un compte ou un mapping déjà utilisé ne peut pas être retiré. */
    public function down(): void
    {
        //
    }
};
