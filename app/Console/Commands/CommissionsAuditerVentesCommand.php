<?php

namespace App\Console\Commands;

use App\Enums\CommissionGenerationStatut;
use App\Enums\DeclencheurCommissionVente;
use App\Models\CommandeVente;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\Parametre;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rapprochement dédié aux commissions de vente — liste toute commande
 * éligible (commission_eligible_snapshot) ayant déjà atteint son déclencheur
 * configuré (chargement validé / facture encaissée) mais sans tentative de
 * génération SUCCES : soit une tentative ERREUR ("à régulariser"), soit
 * aucune tentative du tout alors qu'il aurait dû y en avoir une — cf.
 * incident CMD-230826-004, où ce cas restait invisible faute d'outil.
 *
 * Ne modifie jamais rien : lecture seule, jumelle de comptabilite:auditer.
 */
class CommissionsAuditerVentesCommand extends Command
{
    protected $signature = 'commissions:auditer-ventes {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}';

    protected $description = 'Liste les commandes de vente éligibles aux commissions, ayant atteint leur déclencheur, sans génération réussie.';

    public function handle(): int
    {
        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }
        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        $anomalieGlobale = false;

        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})");

            $anomalieGlobale = $this->auditerOrganisation($organization) || $anomalieGlobale;
        }

        $this->newLine();
        if ($anomalieGlobale) {
            $this->error('Anomalie(s) détectée(s) — voir le détail ci-dessus. Corrigez la configuration concernée puis relancez la génération depuis la fiche commande.');

            return self::FAILURE;
        }

        $this->info('Aucune anomalie détectée : toutes les commandes éligibles ont une génération de commission réussie.');

        return self::SUCCESS;
    }

    private function auditerOrganisation(Organization $org): bool
    {
        $processusId = CommissionProcessus::where('organization_id', $org->id)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->value('id');

        $declencheur = Parametre::getDeclencheurCommissionVente($org->id);

        $commandesEligibles = CommandeVente::where('organization_id', $org->id)
            ->where('commission_eligible_snapshot', true)
            ->where(function (Builder $q) use ($declencheur) {
                $declencheur === DeclencheurCommissionVente::CHARGEMENT_VALIDE
                    ? $q->whereNotNull('chargement_valide_at')
                    : $q->whereHas('facture', fn (Builder $f) => $f->where('statut_facture', 'payee'));
            })
            ->get();

        $anomalies = $commandesEligibles->filter(function (CommandeVente $commande) use ($processusId) {
            if (! $processusId) {
                return true;
            }

            $statut = CommissionGenerationAttempt::statutCourant(CommandeVente::class, $commande->id, $processusId);

            return $statut !== CommissionGenerationStatut::SUCCES;
        });

        $this->table(['Commandes éligibles', 'Anomalies'], [[
            $commandesEligibles->count(),
            $anomalies->count() === 0 ? '0' : "⚠ {$anomalies->count()}",
        ]]);

        if ($anomalies->isEmpty()) {
            return false;
        }

        $this->table(
            ['Commande', 'Numéro', 'Statut génération', 'Motif'],
            $anomalies->map(function (CommandeVente $commande) use ($processusId) {
                $derniere = $processusId
                    ? CommissionGenerationAttempt::where('source_type', CommandeVente::class)
                        ->where('source_id', $commande->id)
                        ->where('processus_id', $processusId)
                        ->latest('created_at')
                        ->first()
                    : null;

                return [
                    $commande->id,
                    $commande->numero,
                    $derniere?->statut->label() ?? 'Jamais tentée',
                    $derniere?->motif_erreur ?? '—',
                ];
            })->all(),
        );

        return true;
    }

    private function resolveOrganizations(): ?Collection
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return Organization::query()->get();
        }

        $organizations = Organization::query()
            ->where(function (Builder $q) use ($identifiants) {
                $q->whereIn('id', $identifiants)
                    ->orWhereIn('code', $identifiants)
                    ->orWhereIn('slug', $identifiants);
            })
            ->get();

        $trouves = $organizations->flatMap(fn (Organization $o) => array_filter([$o->id, $o->code, $o->slug]));
        $manquants = array_diff($identifiants, $trouves->all());
        if (! empty($manquants)) {
            $this->error('Organisation(s) introuvable(s) : '.implode(', ', $manquants));

            return null;
        }

        return $organizations;
    }
}
