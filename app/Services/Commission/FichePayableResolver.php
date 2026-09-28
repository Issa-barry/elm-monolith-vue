<?php

namespace App\Services\Commission;

use App\Enums\StatutPeriodePaiement;
use App\Models\PaiementFiche;
use App\Models\User;
use App\Services\PeriodeComptableService;
use App\Services\Tresorerie\DecaissementFicheResolver;
use Illuminate\Support\Collection;

/**
 * Relie une ligne d'un écran Commissions (un bénéficiaire) à la PaiementFiche qui porte
 * son paiement. Le paiement lancé depuis ces écrans est toujours enregistré SUR cette fiche
 * (PaiementFichePaiementController::store) — jamais une seconde chaîne de paiement : la
 * fiche reste la seule source du montant payé, et l'allocation sur les
 * CommissionEnveloppePart se fait comme depuis l'écran de la fiche.
 *
 * Retient, par bénéficiaire, la fiche la plus ancienne encore due dont la période est
 * validée et que l'utilisateur a le droit de payer (PaiementFichePolicy::payer). Sans
 * filtre de période, une ligne peut couvrir plusieurs fiches : elles se paient alors une
 * par une, de la plus ancienne à la plus récente.
 */
final class FichePayableResolver
{
    /**
     * @param  list<string>  $beneficiaireIds
     * @return Collection<string, array<string, mixed>> cf. presenter()
     */
    public static function pourBeneficiaires(
        User $user,
        string $beneficiaireType,
        array $beneficiaireIds,
        string $periodeCode = '',
    ): Collection {
        if ($beneficiaireIds === [] || ! $user->can('comptabilite.payer')) {
            return collect();
        }

        $fiches = PaiementFiche::query()
            ->with('periode')
            ->where('organization_id', $user->organization_id)
            ->where('beneficiaire_type', $beneficiaireType)
            ->whereIn('beneficiaire_id', $beneficiaireIds)
            ->whereColumn('montant_paye', '<', 'montant_net')
            ->whereHas('periode', function ($q) use ($periodeCode) {
                $q->where('statut', StatutPeriodePaiement::VALIDEE->value);
                if ($periodeCode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($periodeCode);
                    $q->where('date_debut', '<=', $fin->toDateString())
                        ->where('date_fin', '>=', $debut->toDateString());
                }
            })
            ->get()
            ->sortBy(fn (PaiementFiche $f) => $f->periode->date_debut->toDateString().'|'.$f->reference);

        $retenues = $fiches
            ->filter(fn (PaiementFiche $f) => $user->can('payer', $f))
            ->groupBy(fn (PaiementFiche $f) => (string) $f->beneficiaire_id)
            ->map(fn (Collection $group) => $group->first());

        $tresorerie = app(DecaissementFicheResolver::class)->optionsPourFiches($retenues->values(), $user);

        return $retenues->map(fn (PaiementFiche $fiche) => self::presenter($fiche, $tresorerie[$fiche->id]));
    }

    /**
     * Données du dialogue de paiement d'une fiche (PaymentCard) — partagées entre les écrans
     * Commissions et l'écran de la fiche.
     *
     * @param  array<string, mixed>  $tresorerie  cf. DecaissementFicheResolver::optionsPourFiches()
     * @return array<string, mixed>
     */
    public static function presenter(PaiementFiche $fiche, array $tresorerie): array
    {
        return [
            'id' => $fiche->id,
            'reference' => $fiche->reference,
            'beneficiaire_type' => $fiche->beneficiaire_type,
            'beneficiaire_nom' => $fiche->beneficiaire_nom,
            'periode_reference' => $fiche->periode?->reference,
            'periode_debut' => $fiche->periode?->date_debut?->toDateString(),
            'periode_fin' => $fiche->periode?->date_fin?->toDateString(),
            'montant_net' => (float) $fiche->montant_net,
            'montant_paye' => (float) $fiche->montant_paye,
            'montant_restant' => $fiche->montant_restant,
            'tresorerie' => $tresorerie,
        ];
    }
}
