<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comptes de la commission parrain (ADR 0035, lot 3) pour les organisations existantes : 622500
 * « Commissions parrains », 467210 « Parrains — commissions à payer », 467220 « Parrains — avances
 * à récupérer », et les mappings `fiche_parrain_validee` / `paiement_parrain` / réaffectation.
 * Bootstrap idempotent : ne crée que ce qui manque, ne modifie aucun compte ni mapping existant.
 *
 * Un de ces numéros déjà créé à la main pour un autre usage serait réutilisé tel quel par
 * firstOrCreate() : signalé dans le journal (sans bloquer), pour correction par le comptable.
 */
return new class extends Migration
{
    private const COMPTES = ['622500', '467210', '467220'];

    public function up(): void
    {
        $existants = DB::table('compta_comptes')
            ->whereIn('numero', self::COMPTES)
            ->where('libelle', 'not like', '%arrain%')
            ->get(['organization_id', 'numero', 'libelle']);

        foreach ($existants as $compte) {
            Log::warning('Commission parrain : un compte existant porte déjà ce numéro pour un autre usage, il sera réutilisé tel quel.', (array) $compte);
        }

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
