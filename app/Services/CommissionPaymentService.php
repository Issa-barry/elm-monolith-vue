<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\CommissionPaymentItem;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommissionPaymentService
{
    /**
     * Enregistre un paiement groupé pour un bénéficiaire + véhicule.
     * Allocation FIFO : les parts dont earned_at est le plus ancien sont soldées en premier.
     *
     * @param  string  $beneficiaryType  livreur|proprietaire
     * @param  float  $montant  montant total à payer
     * @param  string  $paidAt  date ISO Y-m-d
     *
     * @throws InvalidArgumentException
     */
    public static function payer(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): CommissionPayment {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponibles($vehicule, $beneficiaryType, $beneficiaryId);
        $totalDisponible = $parts->sum(fn ($p) => $p->montant_restant);

        if ($montant > $totalDisponible + 0.009) {
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        $touched = PeriodePayabilityChecker::touchedUntilAmount($parts, $montant, fn ($p) => (float) $p->montant_restant);
        PeriodePayabilityChecker::assertPartsPayable($touched);
        PeriodePayabilityChecker::assertPartsNotClaimedByFiche($touched, $beneficiaryType, $beneficiaryId);

        $beneficiaryNom = $parts->first()?->beneficiaire_nom ?? 'Inconnu';

        return DB::transaction(function () use (
            $vehicule, $beneficiaryType, $beneficiaryId, $beneficiaryNom,
            $montant, $modePaiement, $paidAt, $note, $parts
        ) {
            $payment = CommissionPayment::create([
                'organization_id' => $vehicule->organization_id,
                'vehicule_id' => $vehicule->id,
                'livreur_id' => $beneficiaryType === 'livreur' ? $beneficiaryId : null,
                'proprietaire_id' => $beneficiaryType === 'proprietaire' ? $beneficiaryId : null,
                'beneficiary_type' => $beneficiaryType,
                'beneficiary_nom' => $beneficiaryNom,
                'montant' => $montant,
                'mode_paiement' => $modePaiement,
                'note' => $note,
                'paid_at' => $paidAt,
                'created_by' => Auth::id(),
            ]);

            $restant = $montant;
            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }
                $alloue = min($restant, (float) $part->montant_restant);
                CommissionPaymentItem::create([
                    'payment_id' => $payment->id,
                    'part_id' => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);
                $part->recalculStatut();
                $restant = round($restant - $alloue, 2);
            }

            return $payment->load('items');
        });
    }

    // ── API globale livreur (sans contrainte de véhicule) ────────────────────

    /**
     * Soldes agrégés par livreur pour toute une organisation.
     * Retourne les colonnes : livreur_id, beneficiaire_nom, impaye, paye, premiere_echeance, fiche_id.
     *
     * Dès qu'une PaiementFiche a repris une part (PeriodeCalculatorService::creerFiche), le
     * paiement direct de cette part est verrouillé (PeriodePayabilityChecker::assertPartsNotClaimedByFiche)
     * et son solde ne se lit plus sur montant_verse/statut — jamais mis à jour par un paiement
     * de fiche (PaiementFichePaiementController ne touche que PaiementFiche/PaiementFichePaiement).
     * Pour ces parts, on relit le solde côté fiche : celle-ci n'expose qu'un montant_paye global
     * (aucune allocation par ligne, contrairement au FIFO du paiement direct), on répartit donc sa
     * fraction payée uniformément sur les lignes issues de commissions logistiques.
     *
     * @param  string|array<int, string>|null  $siteId  Un ID de site (rétro-compat) ou un tableau d'IDs.
     */
    public static function soldesParLivreur(string $orgId, ?string $periode = null, string|array|null $siteId = null): \Illuminate\Support\Collection
    {
        $siteIds = array_values(array_filter((array) $siteId));

        $parts = CommissionLogistiquePart::query()
            ->select(['id', 'livreur_id', 'beneficiaire_nom', 'statut', 'montant_actuel', 'montant_net', 'montant_verse', 'earned_at'])
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($periode, fn ($q) => $q->where('periode', $periode))
            ->whereHas('commission', function ($q) use ($orgId, $siteIds) {
                $q->where('organization_id', $orgId);
                if (! empty($siteIds)) {
                    $q->whereHas('transfert', fn ($t) => $t->whereIn('site_source_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
                }
            })
            ->get();

        if ($parts->isEmpty()) {
            return collect();
        }

        $lignesParPartId = PaiementFicheLigne::query()
            ->where('source_type', CommissionLogistiquePart::class)
            ->whereIn('source_id', $parts->pluck('id'))
            ->get(['fiche_id', 'source_id', 'montant'])
            ->keyBy('source_id');

        $fiches = PaiementFiche::query()
            ->whereIn('id', $lignesParPartId->pluck('fiche_id')->unique())
            ->get(['id', 'montant_net', 'montant_paye'])
            ->keyBy('id');

        return $parts->groupBy('livreur_id')->map(function (Collection $livreurParts, string $livreurId) use ($lignesParPartId, $fiches) {
            $impaye = 0.0;
            $paye = 0.0;
            $ficheId = null;
            $ligneMontantParFiche = [];

            foreach ($livreurParts as $part) {
                $ligne = $lignesParPartId->get($part->id);

                if ($ligne === null) {
                    // Circuit direct classique (part pas encore reprise par une fiche).
                    $aPayer = (float) ($part->montant_actuel ?? $part->montant_net);
                    if (in_array($part->statut?->value, [StatutCommission::IMPAYE->value, StatutCommission::PARTIEL->value], true)) {
                        $impaye += max(0.0, $aPayer - (float) $part->montant_verse);
                    }
                    $paye += (float) $part->montant_verse;

                    continue;
                }

                $ligneMontantParFiche[$ligne->fiche_id] = ($ligneMontantParFiche[$ligne->fiche_id] ?? 0.0) + (float) $ligne->montant;
            }

            foreach ($ligneMontantParFiche as $id => $netLogistique) {
                $fiche = $fiches->get($id);
                if ($fiche === null) {
                    continue;
                }

                $ficheId ??= $id;

                $netFiche = (float) $fiche->montant_net;
                $fraction = $netFiche > 0.009 ? min(1.0, (float) $fiche->montant_paye / $netFiche) : 0.0;
                $payeLogistique = round($netLogistique * $fraction, 2);

                $paye += $payeLogistique;
                $impaye += max(0.0, round($netLogistique - $payeLogistique, 2));
            }

            $premiereEcheance = $livreurParts
                ->whereIn('statut', [StatutCommission::IMPAYE, StatutCommission::PARTIEL])
                ->min('earned_at');

            return (object) [
                'livreur_id' => $livreurId,
                'beneficiaire_nom' => $livreurParts->first()->beneficiaire_nom,
                'impaye' => $impaye,
                'paye' => $paye,
                'premiere_echeance' => $premiereEcheance,
                'fiche_id' => $ficheId,
            ];
        })
            ->sortByDesc('impaye')
            ->values();
    }

    /**
     * Parts payables (impayé + partiel) pour un livreur sur toute l'org, triées FIFO.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function partsDisponiblesLivreur(string $livreurId, string $orgId): Collection
    {
        return CommissionLogistiquePart::query()
            ->whereIn('statut', [
                StatutCommission::IMPAYE->value,
                StatutCommission::PARTIEL->value,
            ])
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Relevé de toutes les parts d'un livreur sur toute l'org.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function releveLivreur(string $livreurId, string $orgId): Collection
    {
        return CommissionLogistiquePart::with([
            'commission.transfert:id,reference,date_arrivee_reelle,vehicule_id,site_source_id,site_destination_id',
            'commission.transfert.vehicule:id,nom_vehicule,immatriculation',
            'commission.transfert.siteSource:id,nom',
            'commission.transfert.siteDestination:id,nom',
            'paymentItems.payment:id,paid_at,mode_paiement,montant',
        ])
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Enregistre un paiement global pour un livreur (multi-véhicules, FIFO).
     *
     * @throws InvalidArgumentException
     */
    public static function payerLivreur(
        string $livreurId,
        string $orgId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): CommissionPayment {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponiblesLivreur($livreurId, $orgId);
        $totalDisponible = $parts->sum(fn ($p) => $p->montant_restant);

        if ($montant > $totalDisponible + 0.009) {
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        $touched = PeriodePayabilityChecker::touchedUntilAmount($parts, $montant, fn ($p) => (float) $p->montant_restant);
        PeriodePayabilityChecker::assertPartsPayable($touched);
        PeriodePayabilityChecker::assertPartsNotClaimedByFiche($touched, 'livreur', $livreurId);

        $beneficiaryNom = $parts->first()?->beneficiaire_nom ?? 'Inconnu';

        return DB::transaction(function () use (
            $livreurId, $orgId, $beneficiaryNom, $montant, $modePaiement, $paidAt, $note, $parts
        ) {
            $payment = CommissionPayment::create([
                'organization_id' => $orgId,
                'vehicule_id' => null,
                'livreur_id' => $livreurId,
                'proprietaire_id' => null,
                'beneficiary_type' => 'livreur',
                'beneficiary_nom' => $beneficiaryNom,
                'montant' => $montant,
                'mode_paiement' => $modePaiement,
                'note' => $note,
                'paid_at' => $paidAt,
                'created_by' => Auth::id(),
            ]);

            $restant = $montant;
            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }
                $alloue = min($restant, (float) $part->montant_restant);
                CommissionPaymentItem::create([
                    'payment_id' => $payment->id,
                    'part_id' => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);
                $part->recalculStatut();
                $restant = round($restant - $alloue, 2);
            }

            return $payment->load('items');
        });
    }

    // ── API par véhicule (retro-compat) ──────────────────────────────────────

    /**
     * Parts payables (impayé + partiel) pour un bénéficiaire + véhicule, triées FIFO.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function partsDisponibles(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId
    ): Collection {
        $query = CommissionLogistiquePart::query()
            ->whereIn('statut', [
                StatutCommission::IMPAYE->value,
                StatutCommission::PARTIEL->value,
            ])
            ->where('type_beneficiaire', $beneficiaryType)
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->orderBy('earned_at')
            ->orderBy('id');

        if ($beneficiaryType === 'livreur') {
            $query->where('livreur_id', $beneficiaryId);
        } else {
            $query->where('proprietaire_id', $beneficiaryId);
        }

        return $query->get();
    }

    /**
     * Soldes agrégés pour un véhicule, regroupés par bénéficiaire.
     * Retourne impaye + paye par bénéficiaire.
     */
    public static function soldesParVehicule(Vehicule $vehicule): array
    {
        $rows = CommissionLogistiquePart::query()
            ->selectRaw(
                'type_beneficiaire,
                 COALESCE(livreur_id, proprietaire_id) AS beneficiary_id,
                 beneficiaire_nom,
                 SUM(CASE WHEN statut IN (?,?) THEN CASE WHEN COALESCE(montant_actuel, montant_net) - montant_verse > 0 THEN COALESCE(montant_actuel, montant_net) - montant_verse ELSE 0 END ELSE 0 END) AS impaye,
                 SUM(montant_verse) AS paye,
                 MIN(CASE WHEN statut IN (?,?) THEN earned_at END) AS premiere_echeance',
                [
                    StatutCommission::IMPAYE->value,
                    StatutCommission::PARTIEL->value,
                    StatutCommission::IMPAYE->value,
                    StatutCommission::PARTIEL->value,
                ]
            )
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->groupBy('type_beneficiaire', 'beneficiary_id', 'beneficiaire_nom')
            ->get();

        $result = ['livreurs' => [], 'proprietaires' => []];

        foreach ($rows as $row) {
            $key = $row->type_beneficiaire === 'livreur' ? 'livreurs' : 'proprietaires';
            $result[$key][] = [
                'id' => $row->beneficiary_id,
                'type' => $row->type_beneficiaire,
                'nom' => $row->beneficiaire_nom,
                'impaye' => (float) $row->impaye,
                'paye' => (float) $row->paye,
                'premiere_echeance' => $row->premiere_echeance,
            ];
        }

        return $result;
    }

    /**
     * Relevé détaillé des parts (accruals) pour un bénéficiaire + véhicule.
     */
    public static function releve(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId
    ): Collection {
        $query = CommissionLogistiquePart::with([
            'commission.transfert:id,reference,date_arrivee_reelle',
            'paymentItems.payment:id,paid_at,mode_paiement,montant',
        ])
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->where('type_beneficiaire', $beneficiaryType)
            ->orderBy('earned_at')
            ->orderBy('id');

        if ($beneficiaryType === 'livreur') {
            $query->where('livreur_id', $beneficiaryId);
        } else {
            $query->where('proprietaire_id', $beneficiaryId);
        }

        return $query->get();
    }
}
