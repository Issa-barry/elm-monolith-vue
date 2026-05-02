<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Vehicule;
use App\Services\CommissionVentePaiementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PaiementCommissionVenteController extends Controller
{
    /**
     * POST /commissions/beneficiaires/{type}/{beneficiaireId}/paiements
     *
     * Enregistre un paiement groupé sur l'ensemble des commissions disponibles
     * d'un bénéficiaire (FIFO par date d'éligibilité).
     *
     * Input : montant, mode_paiement, paid_at, note (optionnel)
     * Contrainte : montant ≤ solde réel (brut − frais dépenses − versé)
     */
    public function store(Request $request, string $type, string $beneficiaireId): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422, 'Type bénéficiaire invalide.');

        $data = $request->validate([
            'montant'       => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'paid_at'       => 'required|date',
            'note'          => 'nullable|string|max:2000',
        ], [
            'montant.required'       => 'Le montant est obligatoire.',
            'montant.min'            => 'Le montant doit être supérieur à 0.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'paid_at.required'       => 'La date de paiement est obligatoire.',
        ]);

        $erreur = $this->verifierSoldeProprietaire($type, $beneficiaireId, (float) $data['montant']);
        if ($erreur !== null) {
            return back()->withErrors(['montant' => $erreur]);
        }

        try {
            CommissionVentePaiementService::payer(
                organizationId: auth()->user()->organization_id,
                type: $type,
                beneficiaireId: $beneficiaireId,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: $data['paid_at'],
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement groupé enregistré.');
    }

    /**
     * Retourne un message d'erreur si le paiement propriétaire est impossible,
     * null si tout est correct. Non applicable aux livreurs.
     */
    private function verifierSoldeProprietaire(string $type, string $beneficiaireId, float $montant): ?string
    {
        if ($type !== 'proprietaire') {
            return null;
        }

        $orgId = auth()->user()->organization_id;

        $vehiculeIds = Vehicule::where('proprietaire_id', $beneficiaireId)
            ->where('organization_id', $orgId)
            ->pluck('id');

        $totalFrais = (float) Depense::whereIn('vehicule_id', $vehiculeIds)
            ->where('statut', 'approuve')
            ->where('organization_id', $orgId)
            ->sum('montant');

        $base = CommissionPart::whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'proprietaire')
            ->where('proprietaire_id', $beneficiaireId);

        $soldeReel = max(0.0, (float) (clone $base)->sum('montant_brut') - $totalFrais - (float) (clone $base)->sum('montant_verse'));

        return match (true) {
            $soldeReel <= 0.009 => 'Aucun montant disponible : les frais dépassent ou égalisent la commission brute.',
            $montant > $soldeReel + 0.009 => sprintf(
                'Le montant saisi (%s GNF) dépasse le solde réel disponible (%s GNF).',
                number_format($montant, 0, ',', ' '),
                number_format($soldeReel, 0, ',', ' ')
            ),
            default => null,
        };
    }
}
