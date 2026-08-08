<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypeLignePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\PaieLigne;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PaieVariable;
use App\Models\Proprietaire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PeriodeCalculatorService
{
    public function calculer(PaiementPeriode $periode): array
    {
        if ($periode->isValidee() || $periode->isCloturee()) {
            throw new \LogicException('Impossible de recalculer une période validée ou clôturée.');
        }

        $nbFiches = 0;
        $hash = $this->signatureSource($periode);

        DB::transaction(function () use ($periode, $hash, &$nbFiches) {
            // forceDelete() : PaiementFiche utilise SoftDeletes, mais un delete() classique
            // laisse la ligne physique en place et provoque une violation de la contrainte
            // d'unicité (periode_id, beneficiaire_type, beneficiaire_id) au réinsert suivant.
            $periode->fiches()
                ->where('statut', '!=', StatutFichePaiement::PAYE->value)
                ->get()
                ->each(fn (PaiementFiche $f) => $f->forceDelete());

            $nbFiches = match ($periode->type) {
                TypePeriodePaiement::LIVREUR => $this->calculerLivreurs($periode),
                TypePeriodePaiement::PROPRIETAIRE => $this->calculerProprietaires($periode),
                TypePeriodePaiement::SALARIE => $this->calculerSalaries($periode),
            };

            // Le hash/horodatage sont enregistrés même à 0 fiche : sans données source, on ne
            // veut pas retenter le calcul à chaque ouverture de la page (cf. needsRecalcul()).
            $periode->update([
                'statut' => $nbFiches > 0 ? StatutPeriodePaiement::CALCULEE : $periode->statut,
                'calcul_hash' => $hash,
                'calculated_at' => now(),
            ]);
        });

        return ['nb_fiches' => $nbFiches];
    }

    /**
     * Calcule (ou recalcule) la période uniquement si nécessaire : fiches jamais générées, ou
     * données source (commissions/dépenses/paie) modifiées depuis le dernier calcul. Pensée
     * pour être appelée à chaque ouverture de la page détail sans jamais déclencher de recalcul
     * superflu ni de doublons (le calcul lui-même est idempotent, cf. `calculer()`).
     *
     * @return array{recalcule: bool, nb_fiches: int}
     */
    public function calculerSiNecessaire(PaiementPeriode $periode): array
    {
        if (! $this->needsRecalcul($periode)) {
            return ['recalcule' => false, 'nb_fiches' => $periode->fiches()->count()];
        }

        $result = $this->calculer($periode);

        return ['recalcule' => true, 'nb_fiches' => $result['nb_fiches']];
    }

    /**
     * Point d'entrée appelé dès qu'une donnée impactante change (commission créée/ajustée,
     * dépense validée...) : recalcule immédiatement toute période de l'org couvrant cette date,
     * sans attendre qu'un utilisateur rouvre la page détail. Sans effet si aucune période
     * n'existe encore sur cette fenêtre, et sans risque de doublon/surcoût : `calculerSiNecessaire`
     * ne relance `calculer()` que si le hash source a réellement changé, et jamais sur une
     * période validée/clôturée (cf. needsRecalcul).
     */
    public function recalculerPeriodesConcernees(string $organizationId, Carbon $date): void
    {
        PaiementPeriode::where('organization_id', $organizationId)
            ->whereDate('date_debut', '<=', $date)
            ->whereDate('date_fin', '>=', $date)
            ->get()
            ->each(fn (PaiementPeriode $periode) => $this->calculerSiNecessaire($periode));
    }

    /**
     * Une période validée/clôturée n'est jamais recalculée automatiquement (les montants sont
     * figés) : seul un recalcul manuel explicite pourrait le faire, et `calculer()` l'interdit
     * de toute façon tant qu'elle n'a pas été repassée en brouillon.
     */
    public function needsRecalcul(PaiementPeriode $periode): bool
    {
        if (! $periode->peutEtreCalculee()) {
            return false;
        }

        if ($periode->calculated_at === null) {
            return true;
        }

        return $periode->calcul_hash !== $this->signatureSource($periode);
    }

    /**
     * Empreinte légère des données source dont dépend le calcul de la période, sans jamais
     * charger les lignes en mémoire. On combine un comptage/somme des montants réellement dus
     * (COALESCE montant_actuel/montant_net) à la dernière modification : la somme seule
     * suffirait à détecter un ajustement, mais `updated_at` couvre aussi les cas où un montant
     * ajusté reviendrait par coïncidence à sa valeur théorique d'origine.
     */
    private function signatureSource(PaiementPeriode $periode): string
    {
        $orgId = $periode->organization_id;

        if ($periode->type === TypePeriodePaiement::SALARIE) {
            $paieLignes = PaieLigne::whereHas('periode', function ($q) use ($orgId, $periode) {
                $q->where('organization_id', $orgId)
                    ->whereYear('created_at', $periode->date_debut->year)
                    ->whereMonth('created_at', $periode->date_debut->month);
            })->selectRaw('COUNT(*) as n, SUM(net) as s, MAX(updated_at) as m')->first();

            $paieVariables = PaieVariable::whereHas('ligne.periode', function ($q) use ($orgId, $periode) {
                $q->where('organization_id', $orgId)
                    ->whereYear('created_at', $periode->date_debut->year)
                    ->whereMonth('created_at', $periode->date_debut->month);
            })->selectRaw('COUNT(*) as n, SUM(montant) as s, MAX(updated_at) as m')->first();

            return md5(json_encode([$paieLignes, $paieVariables]));
        }

        $type = $periode->type === TypePeriodePaiement::LIVREUR ? 'livreur' : 'proprietaire';

        $commParts = CommissionPart::where('type_beneficiaire', $type)
            ->whereNotNull("{$type}_id")
            ->whereHas('commission.commande', function ($q) use ($periode, $orgId) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('created_at', [$periode->date_debut->startOfDay(), $periode->date_fin->endOfDay()]);
            })
            ->selectRaw('COUNT(*) as n, SUM(COALESCE(montant_actuel, montant_net)) as s, MAX(updated_at) as m')->first();

        $logParts = CommissionLogistiquePart::where('type_beneficiaire', $type)
            ->whereNotNull("{$type}_id")
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin])
            ->selectRaw('COUNT(*) as n, SUM(COALESCE(montant_actuel, montant_net)) as s, MAX(updated_at) as m')->first();

        $depenses = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', $type)
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->selectRaw('COUNT(*) as n, SUM(montant) as s, MAX(updated_at) as m')->first();

        $depensesVehicule = null;
        if ($periode->type === TypePeriodePaiement::PROPRIETAIRE) {
            $depensesVehicule = Depense::where('organization_id', $orgId)
                ->where('statut', StatutDepense::VALIDE)
                ->where('beneficiaire_type', 'vehicule')
                ->whereNotNull('beneficiaire_id')
                ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
                ->selectRaw('COUNT(*) as n, SUM(montant) as s, MAX(updated_at) as m')->first();
        }

        return md5(json_encode([$commParts, $logParts, $depenses, $depensesVehicule]));
    }

    private function calculerLivreurs(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionPart::where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('commission.commande', function ($q) use ($periode, $orgId) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('created_at', [$periode->date_debut->startOfDay(), $periode->date_fin->endOfDay()]);
            })
            ->with(['commission.commande', 'livreur'])
            ->get()
            ->groupBy('livreur_id');

        $logParts = CommissionLogistiquePart::where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin])
            ->with(['commission.transfert', 'livreur'])
            ->get()
            ->groupBy('livreur_id');

        $depenses = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', 'livreur')
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->with('depenseType')
            ->get()
            ->groupBy('beneficiaire_id');

        $livreurIds = $commParts->keys()->merge($logParts->keys())->unique();

        $count = 0;

        foreach ($livreurIds as $livreurId) {
            $livreur = Livreur::find($livreurId);
            if (! $livreur) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();
            $montantParSite = [];

            foreach ($commParts->get($livreurId, collect()) as $part) {
                // Une part déjà intégralement versée (y compris via l'ancien
                // circuit de paiement par bénéficiaire, cf. montant_verse) ne
                // doit plus jamais réapparaître dans une fiche — sinon elle
                // gonflerait à tort le "reste à payer" (montant_paye de la
                // fiche démarre à 0 et ne connaît pas cet ancien versement).
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->commission->commande->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionPart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                // Site historique de la vente (CommandeVente.site_id, figé à la
                // création de la commande) — jamais le site courant du véhicule,
                // qui peut avoir changé depuis (cf. BesoinTresorerieService).
                $this->accumulerSite($montantParSite, $part->commission->commande?->site_id, $montant);
            }

            foreach ($logParts->get($livreurId, collect()) as $part) {
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->commission->transfert->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionLogistiquePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_LOGISTIQUE->value,
                    'libelle' => 'Commission logistique '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                $site = $part->commission->transfert ? CommissionLogistiqueService::resolveSiteResponsable($part->commission->transfert) : null;
                $this->accumulerSite($montantParSite, $site, $montant);
            }

            foreach ($depenses->get($livreurId, collect()) as $dep) {
                $lignes->push([
                    'source_type' => Depense::class,
                    'source_id' => $dep->id,
                    'type_ligne' => TypeLignePaiement::DEPENSE->value,
                    'libelle' => $dep->depenseType?->libelle ?? 'Dépense',
                    'montant' => -(float) $dep->montant,
                    'ordre' => $ordre++,
                ]);
            }

            if ($lignes->isEmpty()) {
                continue;
            }

            $this->creerFiche($periode, 'livreur', $livreurId, $livreur->libelleAffichage(), $this->resolveSitePrincipal($montantParSite), $lignes);
            $count++;
        }

        return $count;
    }

    private function calculerProprietaires(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionPart::where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id')
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('commission.commande', function ($q) use ($periode, $orgId) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('created_at', [$periode->date_debut->startOfDay(), $periode->date_fin->endOfDay()]);
            })
            ->with(['commission.commande', 'proprietaire'])
            ->get()
            ->groupBy('proprietaire_id');

        $logParts = CommissionLogistiquePart::where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id')
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin])
            ->with(['commission.transfert', 'proprietaire'])
            ->get()
            ->groupBy('proprietaire_id');

        $depensesProprietaire = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', 'proprietaire')
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->with('depenseType')
            ->get()
            ->groupBy('beneficiaire_id');

        $depensesVehicule = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', 'vehicule')
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->with(['vehiculeBeneficiaire', 'depenseType'])
            ->get()
            ->groupBy(fn ($d) => $d->vehiculeBeneficiaire?->proprietaire_id);

        $proprietaireIds = $commParts->keys()->merge($logParts->keys())->unique();

        $count = 0;

        foreach ($proprietaireIds as $proprietaireId) {
            $proprietaire = Proprietaire::find($proprietaireId);
            if (! $proprietaire) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();
            $montantParSite = [];

            foreach ($commParts->get($proprietaireId, collect()) as $part) {
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->commission->commande->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionPart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                $this->accumulerSite($montantParSite, $part->commission->commande?->site_id, $montant);
            }

            foreach ($logParts->get($proprietaireId, collect()) as $part) {
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->commission->transfert->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionLogistiquePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_LOGISTIQUE->value,
                    'libelle' => 'Commission logistique '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                $site = $part->commission->transfert ? CommissionLogistiqueService::resolveSiteResponsable($part->commission->transfert) : null;
                $this->accumulerSite($montantParSite, $site, $montant);
            }

            foreach ($depensesProprietaire->get($proprietaireId, collect()) as $dep) {
                $lignes->push([
                    'source_type' => Depense::class,
                    'source_id' => $dep->id,
                    'type_ligne' => TypeLignePaiement::DEPENSE->value,
                    'libelle' => $dep->depenseType?->libelle ?? 'Dépense',
                    'montant' => -(float) $dep->montant,
                    'ordre' => $ordre++,
                ]);
            }

            foreach ($depensesVehicule->get($proprietaireId, collect()) as $dep) {
                $lignes->push([
                    'source_type' => Depense::class,
                    'source_id' => $dep->id,
                    'type_ligne' => TypeLignePaiement::DEPENSE->value,
                    'libelle' => $dep->depenseType?->libelle ?? 'Dépense véhicule',
                    'montant' => -(float) $dep->montant,
                    'ordre' => $ordre++,
                ]);
            }

            if ($lignes->isEmpty()) {
                continue;
            }

            $this->creerFiche($periode, 'proprietaire', $proprietaireId, $proprietaire->nom_complet, $this->resolveSitePrincipal($montantParSite), $lignes);
            $count++;
        }

        return $count;
    }

    private function calculerSalaries(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $paieLines = PaieLigne::whereHas('periode', function ($q) use ($orgId, $periode) {
            $q->where('organization_id', $orgId)
                ->whereYear('created_at', $periode->date_debut->year)
                ->whereMonth('created_at', $periode->date_debut->month);
        })
            ->with(['employe', 'variables', 'periode'])
            ->get();

        $count = 0;

        foreach ($paieLines as $ligne) {
            if (! $ligne->employe) {
                continue;
            }

            $ordre = 1;
            $lignesData = collect();

            $lignesData->push([
                'source_type' => PaieLigne::class,
                'source_id' => $ligne->id,
                'type_ligne' => TypeLignePaiement::SALAIRE->value,
                'libelle' => 'Salaire de base',
                'montant' => (float) $ligne->salaire_base,
                'ordre' => $ordre++,
            ]);

            foreach ($ligne->variables as $variable) {
                $typeLigne = match ($variable->type?->value) {
                    'prime' => TypeLignePaiement::PRIME->value,
                    'avance' => TypeLignePaiement::AVANCE->value,
                    'retenue' => TypeLignePaiement::RETENUE->value,
                    'autre_gain' => TypeLignePaiement::PRIME->value,
                    default => TypeLignePaiement::AJUSTEMENT->value,
                };
                $isDeduction = $variable->type?->estDeduction() ?? false;
                $lignesData->push([
                    'source_type' => PaieVariable::class,
                    'source_id' => $variable->id,
                    'type_ligne' => $typeLigne,
                    'libelle' => $variable->libelle,
                    'montant' => $isDeduction ? -(float) $variable->montant : (float) $variable->montant,
                    'ordre' => $ordre++,
                ]);
            }

            $this->creerFiche($periode, 'salarie', $ligne->employe_id, $ligne->employe->nom_complet, $ligne->employe->site_id, $lignesData);
            $count++;
        }

        return $count;
    }

    /** @param  array<string, float>  $montantParSite */
    private function accumulerSite(array &$montantParSite, ?string $siteId, float $montant): void
    {
        if ($siteId === null || $montant <= 0) {
            return;
        }

        $montantParSite[$siteId] = ($montantParSite[$siteId] ?? 0.0) + $montant;
    }

    /**
     * Site à rattacher à la fiche d'un bénéficiaire (livreur/propriétaire) :
     * celui qui pèse le plus dans son montant sur la période. Un même
     * bénéficiaire peut avoir des parts issues de plusieurs véhicules/sites
     * sur la même quinzaine (rare, remplacement ponctuel) — la fiche ne
     * porte qu'un site (colonne unique), donc on retient le site majoritaire ;
     * le calcul du besoin de trésorerie n'en est affecté qu'à la marge dans ce
     * cas limite. `null` si aucune ligne n'a pu être rattachée à un site
     * (ex : commande sans site_id).
     *
     * @param  array<string, float>  $montantParSite
     */
    private function resolveSitePrincipal(array $montantParSite): ?string
    {
        if (empty($montantParSite)) {
            return null;
        }

        arsort($montantParSite);

        return array_key_first($montantParSite);
    }

    private function creerFiche(PaiementPeriode $periode, string $type, string $beneficiaireId, string $nom, ?string $siteId, $lignes): void
    {
        $brut = (float) $lignes->where('montant', '>', 0)->sum('montant');
        $deductions = abs((float) $lignes->where('montant', '<', 0)->sum('montant'));
        $net = $brut - $deductions;

        $fiche = PaiementFiche::create([
            'organization_id' => $periode->organization_id,
            'periode_id' => $periode->id,
            'reference' => $this->genererReferenceFiche($periode),
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId,
            'beneficiaire_nom' => $nom,
            'site_id' => $siteId,
            'montant_brut' => $brut,
            'total_deductions' => $deductions,
            'montant_net' => max(0, $net),
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);

        $fiche->lignes()->createMany($lignes->toArray());
    }

    private function genererReferenceFiche(PaiementPeriode $periode): string
    {
        $count = PaiementFiche::where('periode_id', $periode->id)->count();

        return 'FICHE-'.$periode->reference.'-'.str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
