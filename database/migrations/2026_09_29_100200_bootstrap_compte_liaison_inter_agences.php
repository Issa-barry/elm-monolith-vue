<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compte de liaison entre agences (181000) et ses mappings — encaissement pour le compte d'une
 * autre agence et règlement inter-agences (ADR 0012) — pour les organisations existantes. Le
 * bootstrap du plan comptable est idempotent : il ne crée que ce qui manque et ne modifie jamais
 * un compte ou un mapping déjà configuré par l'organisation.
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
