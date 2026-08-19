<?php

namespace App\Console\Commands;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Organization;
use App\Models\Parametre;
use Illuminate\Console\Command;

/**
 * Phase 0 + pont Phase 1 (cf. conception cible §F) : seed idempotent, par
 * organisation, de commission_processus + commission_cible_types (référentiel
 * global) + les deux commission_regles "pont" (marge_operation) nécessaires à
 * CommissionEnveloppeGenerator::genererPourCommandeVente().
 *
 * N'active RIEN par défaut : commission_processus.statut = inactif tant que
 * --activer n'est pas passé explicitement. Une organisation sans processus
 * ACTIF reste totalement inerte pour le nouveau moteur (cf.
 * CommissionEnveloppeGenerator::genererPourCommandeVente()).
 *
 * Usage :
 *   php artisan commissions:v2:seed-fondations {organization? : ID, ou toutes si omis}
 *                                               {--activer : active le processus vente immédiatement}
 */
class SeedCommissionsV2FondationsCommand extends Command
{
    protected $signature = 'commissions:v2:seed-fondations
                            {organization? : ID de l\'organisation (toutes si omis)}
                            {--activer : Active immédiatement le processus vente pour cette organisation}';

    protected $description = 'Seed Phase 0/1 du nouveau moteur de commissions : processus, cibles, règles pont';

    public function handle(): int
    {
        $this->seedReferentielCibles();

        $orgId = $this->argument('organization');
        $organizations = $orgId
            ? Organization::where('id', $orgId)->get()
            : Organization::all();

        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        foreach ($organizations as $org) {
            $this->seedPourOrganisation($org->id, (bool) $this->option('activer'));
            $this->line("  ✓ {$org->id} ({$org->name})");
        }

        $this->info('Terminé — '.$organizations->count().' organisation(s).');

        return self::SUCCESS;
    }

    private function seedReferentielCibles(): void
    {
        CommissionCibleType::firstOrCreate(
            ['organization_id' => null, 'code' => CommissionCibleType::CODE_PROPRIETAIRE],
            ['libelle' => 'Propriétaire', 'periodicite' => CommissionCibleType::PERIODICITE_MENSUELLE],
        );
        CommissionCibleType::firstOrCreate(
            ['organization_id' => null, 'code' => CommissionCibleType::CODE_EQUIPE_LIVRAISON],
            ['libelle' => 'Équipe de livraison', 'periodicite' => CommissionCibleType::PERIODICITE_QUINZAINE],
        );
    }

    private function seedPourOrganisation(string $organizationId, bool $activer): void
    {
        $declencheurVente = Parametre::getDeclencheurCommissionVente($organizationId)->value;
        $declencheurLogistique = Parametre::getDeclencheurCommissionLogistique($organizationId)->value;

        $processusVente = CommissionProcessus::firstOrCreate(
            ['organization_id' => $organizationId, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => $declencheurVente,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::INACTIF->value,
            ],
        );

        CommissionProcessus::firstOrCreate(
            ['organization_id' => $organizationId, 'code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT],
            [
                'libelle' => 'Transfert logistique',
                'declencheur' => $declencheurLogistique,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::DESTINATION->value,
                'statut' => CommissionActivationStatut::INACTIF->value,
            ],
        );

        if ($activer && $processusVente->statut !== CommissionActivationStatut::ACTIF) {
            $processusVente->update(['statut' => CommissionActivationStatut::ACTIF->value]);
        }

        $today = now()->toDateString();

        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'processus_id' => $processusVente->id,
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => CommissionScopeType::GLOBAL->value,
                'statut' => CommissionRegleStatut::ACTIVE->value,
            ],
            [
                'libelle' => 'Propriétaire — pont Phase 1 (marge de l\'opération)',
                'scope_id' => null,
                'mode' => CommissionMode::DIRECT->value,
                'unite_calcul' => CommissionUniteCalcul::MARGE_OPERATION->value,
                'montant' => null,
                'effective_from' => $today,
            ],
        );

        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'processus_id' => $processusVente->id,
                'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                'scope_type' => CommissionScopeType::GLOBAL->value,
                'statut' => CommissionRegleStatut::ACTIVE->value,
            ],
            [
                'libelle' => 'Équipe de livraison — pont Phase 1 (marge de l\'opération)',
                'scope_id' => null,
                'mode' => CommissionMode::A_REPARTIR->value,
                'unite_calcul' => CommissionUniteCalcul::MARGE_OPERATION->value,
                'montant' => null,
                'effective_from' => $today,
            ],
        );
    }
}
