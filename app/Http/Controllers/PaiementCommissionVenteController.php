<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\CommissionPayeeNotification;
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
        $this->authorize('viewAny', CommandeVente::class);

        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422, 'Type bénéficiaire invalide.');

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'paid_at' => 'nullable|date',
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
        ]);

        $data['paid_at'] ??= now()->toDateString();

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

        $this->notifierBeneficiaire($type, $beneficiaireId, (float) $data['montant'], $data['mode_paiement'], $data['note'] ?? null);

        return back()->with('success', 'Paiement groupé enregistré.');
    }

    private function notifierBeneficiaire(string $type, string $beneficiaireId, float $montant, string $modePaiement, ?string $note): void
    {
        $user = $type === 'livreur'
            ? $this->userDuLivreur(Livreur::find($beneficiaireId))
            : $this->userDuProprietaire(Proprietaire::find($beneficiaireId));

        $user?->notify(new CommissionPayeeNotification($montant, $modePaiement, $note));
    }

    private function userDuLivreur(?Livreur $livreur): ?User
    {
        if (! $livreur) {
            return null;
        }

        if ($livreur->user_id) {
            return User::find($livreur->user_id);
        }

        return $livreur->telephone ? User::where('telephone', $livreur->telephone)->first() : null;
    }

    private function userDuProprietaire(?Proprietaire $proprietaire): ?User
    {
        if (! $proprietaire) {
            return null;
        }

        if ($proprietaire->user_id) {
            return User::find($proprietaire->user_id);
        }

        return $proprietaire->telephone ? User::where('telephone', $proprietaire->telephone)->first() : null;
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

        $totalFrais = (float) Depense::where('beneficiaire_type', 'vehicule')
            ->whereIn('beneficiaire_id', $vehiculeIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId)
            ->sum('montant');

        $base = CommissionPart::whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'proprietaire')
            ->where('proprietaire_id', $beneficiaireId);

        $soldeReel = max(0.0, (float) (clone $base)->sum('montant_brut') - $totalFrais - (float) (clone $base)->sum('montant_verse'));

        return match (true) {
            $soldeReel <= 0.009 => 'Aucun montant disponible : les dépenses dépassent ou égalisent la commission brute.',
            $montant > $soldeReel + 0.009 => sprintf(
                'Le montant saisi (%s GNF) dépasse le solde réel disponible (%s GNF).',
                number_format($montant, 0, ',', ' '),
                number_format($soldeReel, 0, ',', ' ')
            ),
            default => null,
        };
    }
}
