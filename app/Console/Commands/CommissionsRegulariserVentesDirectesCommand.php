<?php

namespace App\Console\Commands;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionRegle;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Régularisation ponctuelle (13/09/2026, correctif CommissionEnveloppeGenerator::
 * genererDepuisContexte()) : avant ce correctif, toute vente directe
 * (CommandeVenteService::creerFactureDirecte(), commande sans véhicule) calculait ses
 * commissions Consultant/Site sur quantite_chargee — colonne structurellement jamais renseignée
 * sur ce chemin, faute d'étape de chargement — d'où un montant systématiquement à 0 GNF, quel que
 * soit le barème réellement configuré (contredisant COMM-008/COMM-009, cf. docs/commissions.md).
 *
 * Cette commande recalcule, pour les enveloppes déjà générées AVANT le correctif, le montant
 * qu'elles auraient dû avoir en se basant sur quantite_demandee de la ligne source — jamais une
 * nouvelle génération, jamais une cible ajoutée ou retirée : seule la valeur numérique déjà
 * présente (enveloppe + lignes + parts) est corrigée, en réutilisant le CommissionRegle déjà
 * résolu et stocké à la génération (jamais une nouvelle résolution qui pourrait diverger si le
 * barème a changé depuis).
 *
 * Champ d'application strict, pour ne jamais toucher un 0 GNF légitime :
 * - source_type = CommandeVente, cible_type IN (consultant, site) — PROPRIETAIRE/EQUIPE_LIVRAISON
 *   sont structurellement impossibles sans véhicule, donc jamais concernées par ce bug ;
 * - montant_total = 0 ET statut = CREEE (jamais une commission déjà activée/payée) ;
 * - commande sans véhicule (vehicule_id null — sinon ce n'est pas le bug ciblé ici, cf. rapport) ;
 * - au moins une ligne dont quantite_demandee > 0 ET dont la règle déjà résolue a un montant > 0.
 *
 * Lecture seule par défaut (aucune écriture sans --apply) — traite chaque enveloppe dans sa
 * propre transaction, jamais un batch global qui échouerait entièrement pour une seule ligne
 * suspecte.
 */
class CommissionsRegulariserVentesDirectesCommand extends Command
{
    protected $signature = 'commissions:regulariser-ventes-directes
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--apply : Applique réellement la correction (sans cette option, simulation seule)}';

    protected $description = 'Recalcule sur quantite_demandee les enveloppes Consultant/Site à 0 GNF générées avant le correctif vente directe.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $orgIds = $this->resolveOrganizationIds();
        if ($orgIds === null) {
            return self::FAILURE;
        }

        $query = CommissionEnveloppe::query()
            ->where('source_type', CommandeVente::class)
            ->whereIn('cible_type', [CommissionCibleType::CODE_CONSULTANT, CommissionCibleType::CODE_SITE])
            ->where('montant_total', 0)
            ->where('statut', StatutCommission::CREEE->value)
            ->with(['lignes', 'parts']);

        if (! empty($orgIds)) {
            $query->whereIn('organization_id', $orgIds);
        }

        $enveloppes = $query->get();

        if ($enveloppes->isEmpty()) {
            $this->info('Aucune enveloppe Consultant/Site à 0 GNF trouvée dans ce périmètre.');

            return self::SUCCESS;
        }

        $commandesById = CommandeVente::whereIn('id', $enveloppes->pluck('source_id')->unique())
            ->with('lignes')
            ->get()
            ->keyBy('id');

        $rows = [];
        $corrigees = 0;
        $ignoreesHorsPerimetre = 0;

        foreach ($enveloppes as $enveloppe) {
            $commande = $commandesById->get($enveloppe->source_id);
            if (! $commande || $commande->vehicule_id !== null) {
                // Pas une vente directe (véhicule présent) : hors périmètre de ce bug précis,
                // ne pas toucher — un 0 GNF avec véhicule a une autre cause, à traiter séparément.
                $ignoreesHorsPerimetre++;

                continue;
            }

            $lignesSourceById = $commande->lignes->keyBy('id');
            $nouveauTotal = 0.0;
            $miseAJourLignes = [];

            foreach ($enveloppe->lignes as $envLigne) {
                $ligneSource = $lignesSourceById->get($envLigne->source_ligne_id);
                if (! $ligneSource) {
                    continue;
                }

                $quantiteDemandee = (float) ($ligneSource->quantite_demandee ?? 0);
                if ($quantiteDemandee <= 0) {
                    continue;
                }

                $regle = CommissionRegle::find($envLigne->commission_regle_id);
                if (! $regle || (float) $regle->montant <= 0) {
                    continue;
                }

                $montantLigne = round($quantiteDemandee * (float) $regle->montant, 2);
                $nouveauTotal = round($nouveauTotal + $montantLigne, 2);
                $miseAJourLignes[] = [
                    'ligne' => $envLigne,
                    'quantite' => $quantiteDemandee,
                    'montant' => $montantLigne,
                ];
            }

            if ($nouveauTotal <= 0.0) {
                // Réellement 0 GNF légitime (barème à 0, ou ligne/règle non résolvable) — ne pas
                // fabriquer un montant qui n'a jamais été dû.
                continue;
            }

            $rows[] = [
                $commande->reference,
                $enveloppe->cible_type,
                $enveloppe->id,
                number_format($nouveauTotal, 0, ',', ' ').' GNF',
            ];

            if ($apply) {
                DB::transaction(function () use ($enveloppe, $miseAJourLignes, $nouveauTotal) {
                    foreach ($miseAJourLignes as $m) {
                        $m['ligne']->update([
                            'quantite' => $m['quantite'],
                            'montant_ligne' => $m['montant'],
                        ]);
                    }
                    $enveloppe->update(['montant_total' => $nouveauTotal]);
                    $enveloppe->parts()->update([
                        'montant_brut' => $nouveauTotal,
                        'montant_net' => $nouveauTotal,
                    ]);
                });
            }
            $corrigees++;
        }

        if (empty($rows)) {
            $this->info('Aucune enveloppe ne correspond au périmètre du correctif (vente directe avec quantité/barème résolvables).');
            if ($ignoreesHorsPerimetre > 0) {
                $this->line("{$ignoreesHorsPerimetre} enveloppe(s) à 0 GNF ignorée(s) car la commande a un véhicule (autre cause, hors périmètre de ce correctif).");
            }

            return self::SUCCESS;
        }

        $this->table(['Commande', 'Cible', 'Enveloppe', 'Nouveau montant'], $rows);

        if ($ignoreesHorsPerimetre > 0) {
            $this->line("{$ignoreesHorsPerimetre} enveloppe(s) à 0 GNF ignorée(s) par ailleurs car la commande a un véhicule (autre cause, hors périmètre de ce correctif).");
        }

        if ($apply) {
            $this->info("{$corrigees} enveloppe(s) régularisée(s).");
        } else {
            $this->warn("{$corrigees} enveloppe(s) seraient régularisée(s) — relancez avec --apply pour écrire en base.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function resolveOrganizationIds(): ?array
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return [];
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

        return $organizations->pluck('id')->all();
    }
}
