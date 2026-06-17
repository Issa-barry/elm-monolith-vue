<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;

class PeriodeCalculatorService
{
    public function calculer(PaiementPeriode $periode): void
    {
        if ($periode->isValidee() || $periode->isCloturee()) {
            throw new \LogicException('Impossible de recalculer une période validée ou clôturée.');
        }

        DB::transaction(function () use ($periode) {
            $periode->fiches()
                ->where('statut', '!=', StatutFichePaiement::PAYE->value)
                ->delete();

            match ($periode->type) {
                TypePeriodePaiement::LIVREUR => $this->calculerLivreurs($periode),
                TypePeriodePaiement::PROPRIETAIRE => $this->calculerProprietaires($periode),
                TypePeriodePaiement::SALARIE => $this->calculerSalaries($periode),
            };

            $periode->update(['statut' => StatutPeriodePaiement::CALCULEE]);
        });
    }

    private function calculerLivreurs(PaiementPeriode $periode): void
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionPart::where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->whereHas('commission.commande', function ($q) use ($periode, $orgId) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('created_at', [$periode->date_debut->startOfDay(), $periode->date_fin->endOfDay()]);
            })
            ->with(['commission.commande', 'livreur'])
            ->get()
            ->groupBy('livreur_id');

        $logParts = CommissionLogistiquePart::where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
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

        foreach ($livreurIds as $livreurId) {
            $livreur = Livreur::find($livreurId);
            if (! $livreur) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();

            foreach ($commParts->get($livreurId, collect()) as $part) {
                $ref = $part->commission->commande->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionPart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => (float) $part->montant_net,
                    'ordre' => $ordre++,
                ]);
            }

            foreach ($logParts->get($livreurId, collect()) as $part) {
                $ref = $part->commission->transfert->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionLogistiquePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_LOGISTIQUE->value,
                    'libelle' => 'Commission logistique '.$ref,
                    'montant' => (float) $part->montant_net,
                    'ordre' => $ordre++,
                ]);
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

            $this->creerFiche($periode, 'livreur', $livreurId, $livreur->nom_complet, null, $lignes);
        }
    }

    private function calculerProprietaires(PaiementPeriode $periode): void
    {
        $orgId = $periode->organization_id;

        $commParts = CommissionPart::where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id')
            ->whereHas('commission.commande', function ($q) use ($periode, $orgId) {
                $q->where('organization_id', $orgId)
                    ->whereBetween('created_at', [$periode->date_debut->startOfDay(), $periode->date_fin->endOfDay()]);
            })
            ->with(['commission.commande', 'proprietaire'])
            ->get()
            ->groupBy('proprietaire_id');

        $logParts = CommissionLogistiquePart::where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id')
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

        foreach ($proprietaireIds as $proprietaireId) {
            $proprietaire = Proprietaire::find($proprietaireId);
            if (! $proprietaire) {
                continue;
            }

            $ordre = 1;
            $lignes = collect();

            foreach ($commParts->get($proprietaireId, collect()) as $part) {
                $ref = $part->commission->commande->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionPart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_VENTE->value,
                    'libelle' => 'Commission vente '.$ref,
                    'montant' => (float) $part->montant_net,
                    'ordre' => $ordre++,
                ]);
            }

            foreach ($logParts->get($proprietaireId, collect()) as $part) {
                $ref = $part->commission->transfert->reference ?? '—';
                $lignes->push([
                    'source_type' => CommissionLogistiquePart::class,
                    'source_id' => $part->id,
                    'type_ligne' => TypeLignePaiement::COMMISSION_LOGISTIQUE->value,
                    'libelle' => 'Commission logistique '.$ref,
                    'montant' => (float) $part->montant_net,
                    'ordre' => $ordre++,
                ]);
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

            $this->creerFiche($periode, 'proprietaire', $proprietaireId, $proprietaire->nom_complet, null, $lignes);
        }
    }

    private function calculerSalaries(PaiementPeriode $periode): void
    {
        $orgId = $periode->organization_id;

        $paieLines = PaieLigne::whereHas('periode', function ($q) use ($orgId, $periode) {
            $q->where('organization_id', $orgId)
                ->whereYear('created_at', $periode->date_debut->year)
                ->whereMonth('created_at', $periode->date_debut->month);
        })
            ->with(['employe', 'variables', 'periode'])
            ->get();

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
        }
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
