<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutPeriodeComptable;
use App\Enums\StatutPeriodePaiement;
use App\Models\PaiementFiche;
use App\Models\PeriodeComptable;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clôture une période comptable. Pour toute fiche propriétaire/livreur dont
 * le montant est déjà calculé (état CALCULEE) mais pas encore VALIDEE au
 * moment de la clôture, une écriture de régularisation ("charge à payer" /
 * provision — équivalent SYSCOHADA des comptes 408/4286) est postée pour ne
 * pas perdre le rattachement à l'exercice. Elle sera reprise automatiquement
 * (contrepassée) par FicheComptabilisationService dès que la fiche sera
 * réellement validée, remplacée par l'écriture définitive — jamais de double
 * charge (règle #5 de la spec).
 */
class RegularisationClotureService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    /**
     * @return list<string> IDs des pièces de régularisation créées
     */
    public function cloturerPeriode(PeriodeComptable $periode, ?string $clotureBy = null): array
    {
        if ($periode->isCloturee()) {
            throw new \RuntimeException("La période {$periode->id} est déjà clôturée.");
        }

        return DB::transaction(function () use ($periode, $clotureBy) {
            $regularisees = [];

            $fiches = PaiementFiche::query()
                ->where('organization_id', $periode->organization_id)
                ->whereIn('beneficiaire_type', ['proprietaire', 'livreur'])
                ->where('montant_net', '>', 0)
                ->whereHas('periode', function ($q) use ($periode) {
                    $q->whereIn('statut', [StatutPeriodePaiement::BROUILLON->value, StatutPeriodePaiement::CALCULEE->value])
                        ->where('date_debut', '<=', $periode->date_fin)
                        ->where('date_fin', '>=', $periode->date_debut);
                })
                ->get();

            foreach ($fiches as $fiche) {
                $piece = $this->regulariserFiche($fiche, $periode, $clotureBy);
                if ($piece) {
                    $regularisees[] = $piece->id;
                }
            }

            $periode->update([
                'statut' => StatutPeriodeComptable::CLOTUREE->value,
                'cloture_at' => now(),
                'cloture_by' => $clotureBy,
            ]);

            return $regularisees;
        });
    }

    private function regulariserFiche(PaiementFiche $fiche, PeriodeComptable $periode, ?string $clotureBy): ?PieceComptable
    {
        $beneficiaire = $fiche->getBeneficiaireModel();
        if (! $beneficiaire) {
            return null;
        }

        $lignes = [
            [
                'role' => 'charge_commission_'.$fiche->beneficiaire_type,
                'sens' => 'debit',
                'montant' => (float) $fiche->montant_brut,
            ],
            [
                'role' => 'dette_tiers_provisoire_'.$fiche->beneficiaire_type,
                'sens' => 'credit',
                'montant' => (float) $fiche->montant_net,
                'tiers_type' => $fiche->beneficiaire_type,
                'tiers_model' => $beneficiaire,
            ],
        ];

        if ((float) $fiche->total_deductions > 0) {
            $lignes[] = [
                'role' => 'avance_tiers_'.$fiche->beneficiaire_type,
                'sens' => 'credit',
                'montant' => (float) $fiche->total_deductions,
                'tiers_type' => $fiche->beneficiaire_type,
                'tiers_model' => $beneficiaire,
            ];
        }

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::REGULARISATION_CLOTURE_FICHE,
            source: $fiche,
            organizationId: $fiche->organization_id,
            dateComptable: Carbon::parse($periode->date_fin),
            libelle: "Régularisation clôture — fiche {$fiche->reference} non validée",
            lignes: $lignes,
            siteId: $fiche->site_id,
            createdBy: $clotureBy,
            ignorerVerrouPeriode: true,
        );
    }
}
