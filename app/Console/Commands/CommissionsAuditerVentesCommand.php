<?php

namespace App\Console\Commands;

use App\Enums\CommissionGenerationStatut;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\NatureOperation;
use App\Models\CommandeVente;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\Parametre;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rapprochement dédié aux commissions de vente — liste toute commande vente_standard ayant
 * déjà atteint son déclencheur (chargement validé / facture encaissée pour un véhicule
 * éligible, ou facture directe créée pour une commande sans véhicule) mais sans tentative de
 * génération SUCCES : une tentative ERREUR ou PARTIEL ("à régulariser"), soit aucune tentative
 * du tout alors qu'il aurait dû y en avoir une — cf. incident CMD-230826-004, où ce cas restait
 * invisible faute d'outil.
 *
 * Ne filtre plus sur commission_eligible_snapshot = true (retiré le 05/09/2026, chantier 2A) :
 * ce champ ne conditionne plus que les cibles PROPRIETAIRE/EQUIPE_LIVRAISON — une commande sans
 * véhicule peut désormais générer une commission SITE/CONSULTANT (cf.
 * CommissionEnveloppeGenerator) et doit donc aussi être auditée, sous peine de reproduire
 * l'angle mort qui existait déjà pour Grossiste + Enlèvement avant cette généralisation. Une
 * commande distribution_client (déclencheur = réception validée, jamais chargement/encaissement,
 * cf. CommissionTriggerService) reste hors périmètre de cet audit, comme avant.
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
            ->where('nature_operation', NatureOperation::VENTE_STANDARD->value)
            ->where(function (Builder $q) use ($declencheur) {
                // Véhicule éligible : suit le déclencheur configuré pour l'organisation, comme
                // avant le chantier 2A (05/09/2026).
                $q->where(function (Builder $avecVehicule) use ($declencheur) {
                    $avecVehicule->where('commission_eligible_snapshot', true)
                        ->where(fn (Builder $d) => $declencheur === DeclencheurCommissionVente::CHARGEMENT_VALIDE
                            ? $d->whereNotNull('chargement_valide_at')
                            : $d->whereHas('facture', fn (Builder $f) => $f->where('statut_facture', 'payee')));
                })
                // Sans véhicule (Grossiste + Enlèvement ou tout autre client, cf.
                // CommissionEnveloppeGenerator::genererPourCommandeVente()) : le déclencheur est
                // la création de la facture directe elle-même (CommandeVenteService::
                // creerFactureDirecte()), inconditionnel — jamais chargement/encaissement.
                    ->orWhere(function (Builder $sansVehicule) {
                        $sansVehicule->whereNull('vehicule_id')->whereHas('facture');
                    });
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
