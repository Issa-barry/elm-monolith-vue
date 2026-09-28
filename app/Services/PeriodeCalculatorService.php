<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypeLignePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\PaieLigne;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\PaieVariable;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PeriodeCalculatorService
{
    /** @var Collection<string, true> sources ("type:id") déjà portées par une fiche figée */
    private Collection $lignesFigees;

    /** @var Collection<string, Collection<int, PaiementFiche>> fiches figées par "type:bénéficiaire" */
    private Collection $figeesParBeneficiaire;

    public function calculer(PaiementPeriode $periode): array
    {
        if ($periode->isValidee() || $periode->isCloturee()) {
            throw new \LogicException('Impossible de recalculer une période validée ou clôturée.');
        }

        $nbFiches = 0;
        $hash = $this->signatureSource($periode);

        DB::transaction(function () use ($periode, $hash, &$nbFiches) {
            // ADR 0010 : une fiche figée (paiement reçu, même partiel, ou déduction reportée)
            // n'est jamais supprimée ni recréée — seules les fiches encore ouvertes sont
            // reconstruites. forceDelete() : la contrainte d'unicité inclut les lignes
            // supprimées en douceur.
            $fiches = $periode->fiches()->with('lignes')->get();
            $figees = $fiches->filter(fn (PaiementFiche $f) => $f->estFigee());
            $fiches->reject(fn (PaiementFiche $f) => $f->estFigee())->each(fn (PaiementFiche $f) => $f->forceDelete());

            $this->lignesFigees = $figees->flatMap(fn (PaiementFiche $f) => $f->lignes)
                ->mapWithKeys(fn (PaiementFicheLigne $l) => ["{$l->source_type}:{$l->source_id}" => true]);
            $this->figeesParBeneficiaire = $figees->groupBy(fn (PaiementFiche $f) => "{$f->beneficiaire_type}:{$f->beneficiaire_id}");

            $nbFiches = $figees->count() + match ($periode->type) {
                TypePeriodePaiement::LIVREUR => $this->calculerLivreurs($periode),
                TypePeriodePaiement::PROPRIETAIRE => $this->calculerProprietaires($periode),
                TypePeriodePaiement::SALARIE => $this->calculerSalaries($periode),
                TypePeriodePaiement::SITE => $this->calculerSites($periode),
                TypePeriodePaiement::CONSULTANT => $this->calculerConsultants($periode),
            };

            // Le hash/horodatage sont enregistrés même à 0 fiche : sans données source, on ne
            // veut pas retenter le calcul à chaque ouverture de la page (cf. needsRecalcul()).
            $periode->update([
                'statut' => $nbFiches > 0 ? StatutPeriodePaiement::CALCULEE : $periode->statut,
                'calcul_hash' => $hash,
                'calculated_at' => now(),
            ]);
        });

        // Des commissions déjà validées (ex. propriétaire/site/consultant, validées à la
        // génération) peuvent rendre la période complète dès son calcul.
        app(PeriodeValidationService::class)->validerSiComplete($periode->refresh());

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
    /**
     * Commissions (vente + logistique) qui entreraient dans cette période au calcul mais ne
     * figurent sur aucune de ses fiches — typiquement générées après la validation (une
     * commande encaissée tard mais datée dans la période) : les fiches d'une période validée
     * sont figées (cf. needsRecalcul), ces commissions n'y sont donc jamais payées. Même
     * périmètre que calculerLivreurs()/calculerProprietaires() ; sans objet pour les autres types.
     *
     * @return array{nombre: int, montant: float}
     */
    public function commissionsHorsFiches(PaiementPeriode $periode): array
    {
        // [type de part vente, cible d'enveloppe imposée, type logistique, colonne logistique] —
        // sites et consultants filtrent aussi sur la cible de l'enveloppe (comme calculerSites()/
        // calculerConsultants()) et n'ont pas de commission logistique.
        [$typeVente, $cible, $typeLogistique, $colonneLogistique] = match ($periode->type) {
            TypePeriodePaiement::LIVREUR => [CommissionEnveloppePart::TYPE_LIVREUR, null, 'livreur', 'livreur_id'],
            TypePeriodePaiement::PROPRIETAIRE => [CommissionEnveloppePart::TYPE_PROPRIETAIRE, null, 'proprietaire', 'proprietaire_id'],
            TypePeriodePaiement::SITE => [CommissionEnveloppePart::TYPE_SITE, CommissionCibleType::CODE_SITE, null, null],
            TypePeriodePaiement::CONSULTANT => [CommissionEnveloppePart::TYPE_PRESTATAIRE, CommissionCibleType::CODE_CONSULTANT, null, null],
            default => [null, null, null, null],
        };

        if ($typeVente === null) {
            return ['nombre' => 0, 'montant' => 0.0];
        }

        $orgId = $periode->organization_id;
        $exclus = [StatutCommission::ANNULEE->value, StatutCommission::PAYE->value];

        $surFiches = PaiementFicheLigne::whereIn('fiche_id', $periode->fiches()->select('id'))
            ->get(['source_type', 'source_id'])
            ->map(fn (PaiementFicheLigne $l) => "{$l->source_type}:{$l->source_id}")
            ->flip();

        $vente = CommissionEnveloppePart::where('beneficiaire_type', $typeVente)
            ->whereNotIn('statut', $exclus)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->when($cible, fn ($q) => $q->where('cible_type', $cible))
                ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]))
            ->get()
            ->reject(fn (CommissionEnveloppePart $p) => $surFiches->has(CommissionEnveloppePart::class.":{$p->id}"));

        $logistique = $typeLogistique === null ? collect() : CommissionLogistiquePart::where('type_beneficiaire', $typeLogistique)
            ->whereNotNull($colonneLogistique)
            ->whereNotIn('statut', $exclus)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin])
            ->get()
            ->reject(fn (CommissionLogistiquePart $p) => $surFiches->has(CommissionLogistiquePart::class.":{$p->id}"));

        $parts = $vente->concat($logistique);

        return [
            'nombre' => $parts->count(),
            'montant' => round((float) $parts->sum(fn ($p) => $p->montant_a_payer), 2),
        ];
    }

    // DateTimeInterface : les appelants passent indifféremment Carbon\Carbon ou
    // Illuminate\Support\Carbon (un typage strict faisait échouer l'appel du générateur).
    public function recalculerPeriodesConcernees(string $organizationId, \DateTimeInterface $date): void
    {
        $this->traiterPeriodesPourDates($organizationId, collect([$date]));
    }

    /**
     * Pour chaque période couvrant l'une de ces dates : réouverture si des commissions sont
     * arrivées après sa validation, recalcul si nécessaire, puis validation automatique si
     * toutes ses commissions sont validées (cf. PeriodeValidationService::validerSiComplete).
     *
     * @param  Collection<int, mixed>  $dates
     */
    public function traiterPeriodesPourDates(string $organizationId, Collection $dates): void
    {
        $jours = $dates->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();
        if ($jours->isEmpty()) {
            return;
        }

        $periodes = PaiementPeriode::where('organization_id', $organizationId)
            ->where(function ($q) use ($jours) {
                foreach ($jours as $jour) {
                    $q->orWhere(fn ($w) => $w->whereDate('date_debut', '<=', $jour)->whereDate('date_fin', '>=', $jour));
                }
            })
            ->get();

        $validation = app(PeriodeValidationService::class);

        foreach ($periodes as $periode) {
            $validation->rouvrirSiDesynchronisee($periode);
            $this->calculerSiNecessaire($periode->refresh());
            $validation->validerSiComplete($periode->refresh());
        }
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

        $type = match ($periode->type) {
            TypePeriodePaiement::LIVREUR => 'livreur',
            TypePeriodePaiement::SITE => CommissionEnveloppePart::TYPE_SITE,
            TypePeriodePaiement::CONSULTANT => CommissionEnveloppePart::TYPE_PRESTATAIRE,
            default => 'proprietaire',
        };

        $commPartsQuery = CommissionEnveloppePart::where('beneficiaire_type', $type)
            ->whereHas('enveloppe', function ($q) use ($orgId, $periode) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]);
                // beneficiaire_type=prestataire pourrait un jour recouvrir un autre usage que
                // "consultant" — la cible de l'enveloppe seule distingue les deux, même
                // convention que site (cf. CommissionSiteController).
                if ($periode->type === TypePeriodePaiement::SITE) {
                    $q->where('cible_type', CommissionCibleType::CODE_SITE);
                } elseif ($periode->type === TypePeriodePaiement::CONSULTANT) {
                    $q->where('cible_type', CommissionCibleType::CODE_CONSULTANT);
                }
            });
        $commParts = $commPartsQuery
            ->selectRaw('COUNT(*) as n, SUM(COALESCE(montant_actuel, montant_net)) as s, MAX(updated_at) as m')->first();

        // Ni un site ni un consultant n'ont jamais de commission logistique (transfert), au même
        // titre qu'un gérant de dépôt n'en avait jamais — cf. calculerSites()/calculerConsultants().
        $logParts = in_array($periode->type, [TypePeriodePaiement::SITE, TypePeriodePaiement::CONSULTANT], true)
            ? null
            : CommissionLogistiquePart::where('type_beneficiaire', $type)
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

        $commParts = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]))
            ->with(['enveloppe.source'])
            ->get()
            ->groupBy('beneficiaire_id');

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
                // Cf. calculerLivreurs() : une part déjà intégralement versée ne doit
                // plus jamais réapparaître dans une fiche.
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->enveloppe->source?->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionEnveloppePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                $this->accumulerSite($montantParSite, $part->enveloppe->siteResponsableId(), $montant);
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

            if ($this->creerFiche($periode, 'livreur', $livreurId, $livreur->libelleAffichage(), $this->resolveSitePrincipal($montantParSite), $lignes)) {
                $count++;
            }
        }

        return $count;
    }

    private function calculerProprietaires(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]))
            ->with(['enveloppe.source'])
            ->get()
            ->groupBy('beneficiaire_id');

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
                $ref = $part->enveloppe->source?->reference ?? '—';
                $montant = $part->montant_a_payer;
                $lignes->push([
                    'source_type' => CommissionEnveloppePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => $montant,
                    'ordre' => $ordre++,
                ]);
                $this->accumulerSite($montantParSite, $part->enveloppe->siteResponsableId(), $montant);
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

            if ($this->creerFiche($periode, 'proprietaire', $proprietaireId, $proprietaire->nom_complet, $this->resolveSitePrincipal($montantParSite), $lignes)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Mirroring calculerLivreurs() : pas de CommissionLogistiquePart (un site n'a jamais de
     * commission logistique), filtré par cible_type=site sur l'enveloppe pour rester cohérent
     * même si beneficiaire_type=site n'est aujourd'hui utilisé par aucun autre mécanisme. Le
     * bénéficiaire de la fiche EST le site (jamais un employé/gérant) : le site facturé et le
     * site à rattacher à la fiche sont donc toujours le même — pas besoin de
     * resolveSitePrincipal()/accumulerSite() ici.
     */
    private function calculerSites(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->where('cible_type', CommissionCibleType::CODE_SITE)
                ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]))
            ->with(['enveloppe.source'])
            ->get()
            ->groupBy('beneficiaire_id');

        $depenses = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->with('depenseType')
            ->get()
            ->groupBy('beneficiaire_id');

        $siteIds = $commParts->keys();

        $count = 0;

        foreach ($siteIds as $siteId) {
            $site = Site::find($siteId);
            if (! $site) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();

            foreach ($commParts->get($siteId, collect()) as $part) {
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->enveloppe->source?->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionEnveloppePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission site '.$ref,
                    'montant' => $part->montant_a_payer,
                    'ordre' => $ordre++,
                ]);
            }

            foreach ($depenses->get($siteId, collect()) as $dep) {
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

            if ($this->creerFiche($periode, CommissionEnveloppePart::TYPE_SITE, $siteId, $site->nom, $siteId, $lignes)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Mirroring calculerSites() : le bénéficiaire de la fiche EST le prestataire désigné au
     * moment de chaque commission (snapshotté, jamais recalculé rétroactivement si le consultant
     * courant change depuis) — filtré par cible_type=consultant sur l'enveloppe pour rester
     * cohérent même si beneficiaire_type=prestataire venait à couvrir un autre usage un jour. Un
     * consultant n'est rattaché à aucun site (désignation au niveau organisation, cf.
     * CommissionConsultantAffectation) : $siteId est toujours null en pied de fiche, contrairement
     * à calculerSites().
     */
    private function calculerConsultants(PaiementPeriode $periode): int
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PRESTATAIRE)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->where('cible_type', CommissionCibleType::CODE_CONSULTANT)
                ->whereBetween('earned_at', [$periode->date_debut, $periode->date_fin]))
            ->with(['enveloppe.source'])
            ->get()
            ->groupBy('beneficiaire_id');

        $depenses = Depense::where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE)
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PRESTATAIRE)
            ->whereNotNull('beneficiaire_id')
            ->whereBetween('date_depense', [$periode->date_debut, $periode->date_fin])
            ->with('depenseType')
            ->get()
            ->groupBy('beneficiaire_id');

        $prestataireIds = $commParts->keys();

        $count = 0;

        foreach ($prestataireIds as $prestataireId) {
            $prestataire = Prestataire::find($prestataireId);
            if (! $prestataire) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();

            foreach ($commParts->get($prestataireId, collect()) as $part) {
                if ($part->isPaye()) {
                    continue;
                }
                $ref = $part->enveloppe->source?->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionEnveloppePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission consultant '.$ref,
                    'montant' => $part->montant_a_payer,
                    'ordre' => $ordre++,
                ]);
            }

            foreach ($depenses->get($prestataireId, collect()) as $dep) {
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

            if ($this->creerFiche($periode, CommissionEnveloppePart::TYPE_PRESTATAIRE, $prestataireId, $prestataire->nom_complet ?? $prestataire->reference, null, $lignes)) {
                $count++;
            }
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

            if ($this->creerFiche($periode, 'salarie', $ligne->employe_id, $ligne->employe->nom_complet, $ligne->employe->site_id, $lignesData)) {
                $count++;
            }
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

    /**
     * Crée la fiche ouverte d'un bénéficiaire (ADR 0010). Les lignes déjà portées par une de
     * ses fiches figées sont écartées ; s'il en reste, elles vont sur une fiche de rang
     * suivant (fiche complémentaire), rattachée à la fiche d'origine. Les déductions reportées
     * encore en attente pour ce bénéficiaire y sont imputées. Un solde négatif sur une fiche
     * complémentaire (ou portant un report) est à son tour reporté, jamais perdu.
     *
     * @return bool true si une fiche a été créée
     */
    private function creerFiche(PaiementPeriode $periode, string $type, string $beneficiaireId, string $nom, ?string $siteId, $lignes): bool
    {
        $lignes = collect($lignes)
            ->reject(fn (array $l) => $this->lignesFigees->has("{$l['source_type']}:{$l['source_id']}"))
            ->values();

        if ($lignes->isEmpty()) {
            return false;
        }

        $reports = $this->reportsEnAttente($periode->organization_id, $type, $beneficiaireId);
        $ordre = (int) $lignes->max('ordre') + 1;
        foreach ($reports as $ficheReport) {
            $lignes->push([
                'source_type' => PaiementFiche::class,
                'source_id' => $ficheReport->id,
                'type_ligne' => TypeLignePaiement::REPORT->value,
                'libelle' => "Report de la fiche {$ficheReport->reference}",
                'montant' => -(float) $ficheReport->report_a_deduire,
                'ordre' => $ordre++,
            ]);
        }

        $figees = $this->figeesParBeneficiaire->get("{$type}:{$beneficiaireId}", collect());
        $estComplement = $figees->isNotEmpty();

        $brut = (float) $lignes->where('montant', '>', 0)->sum('montant');
        $deductions = abs((float) $lignes->where('montant', '<', 0)->sum('montant'));
        $net = $brut - $deductions;
        $aReporter = $net < 0 && ($estComplement || $reports->isNotEmpty()) ? round(-$net, 2) : 0.0;

        $fiche = PaiementFiche::create([
            'organization_id' => $periode->organization_id,
            'periode_id' => $periode->id,
            'reference' => $this->genererReferenceFiche($periode),
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId,
            'beneficiaire_nom' => $nom,
            'rang' => $estComplement ? (int) $figees->max('rang') + 1 : 1,
            'fiche_origine_id' => $estComplement ? $figees->sortBy('rang')->first()->id : null,
            'site_id' => $siteId,
            'montant_brut' => $brut,
            'total_deductions' => $deductions,
            'montant_net' => max(0, $net),
            'montant_paye' => 0,
            // Rien à payer quand tout le solde est reporté sur la fiche suivante.
            'statut' => $aReporter > 0 ? StatutFichePaiement::PAYE->value : StatutFichePaiement::A_PAYER->value,
        ]);

        $fiche->lignes()->createMany($lignes->toArray());

        // Posé après les lignes : une fiche portant un report est figée, ses lignes aussi.
        if ($aReporter > 0) {
            $fiche->update(['report_a_deduire' => $aReporter]);
        }

        return true;
    }

    /**
     * Déductions reportées par des fiches du bénéficiaire et pas encore imputées : une
     * imputation est une ligne REPORT pointant la fiche d'origine ; si la fiche qui l'imputait
     * est reconstruite, la ligne disparaît avec elle et le report redevient en attente.
     *
     * @return Collection<int, PaiementFiche>
     */
    private function reportsEnAttente(string $organizationId, string $type, string $beneficiaireId): Collection
    {
        return PaiementFiche::where('organization_id', $organizationId)
            ->where('beneficiaire_type', $type)
            ->where('beneficiaire_id', $beneficiaireId)
            ->where('report_a_deduire', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('paiement_fiche_lignes')
                ->where('paiement_fiche_lignes.source_type', PaiementFiche::class)
                ->whereColumn('paiement_fiche_lignes.source_id', 'paiement_fiches.id'))
            ->orderBy('created_at')
            ->get();
    }

    /** Numéro suivant le plus grand déjà utilisé : les fiches figées conservent le leur. */
    private function genererReferenceFiche(PaiementPeriode $periode): string
    {
        $prefixe = 'FICHE-'.$periode->reference.'-';
        $max = PaiementFiche::withTrashed()
            ->where('periode_id', $periode->id)
            ->pluck('reference')
            ->map(fn (string $ref) => str_starts_with($ref, $prefixe) ? (int) substr($ref, strlen($prefixe)) : 0)
            ->max() ?? 0;

        return $prefixe.str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }
}
