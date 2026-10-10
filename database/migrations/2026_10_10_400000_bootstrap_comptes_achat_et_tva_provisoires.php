<?php

use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Comptes d'achat et de TVA PROVISOIRES pour les organisations existantes (décision du 10/10/2026) :
 * 601000 Achats de marchandises (rôle `achat`) et 445200 TVA récupérable sur achats (rôle
 * `tva_deductible`) de l'événement `facture_fournisseur_validee`. À valider par le comptable.
 *
 * Bootstrap idempotent : ne crée que ce qui manque, n'écrase aucun compte ni aucune correspondance
 * déjà configurés. Aucun rattrapage n'est lancé ici : les factures en attente se relancent
 * volontairement (bouton « Relancer » de la fiche ou `comptabilite:rattraper --type=facture-fournisseur`).
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

    /** Pas de rollback de données : un compte ou une correspondance déjà utilisés ne se retirent pas. */
    public function down(): void
    {
        //
    }
};
