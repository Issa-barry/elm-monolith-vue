<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\VersementCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VersementCommissionController extends Controller
{
    /**
     * Enregistre un versement sur une part précise.
     * Route : POST /commissions/{commission}/parts/{part}/versements
     */
    public function store(Request $request, CommissionVente $commission, CommissionPart $part): RedirectResponse
    {
        abort_unless($commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');
        abort_unless($part->commission_vente_id === $commission->id, 403, 'Part invalide.');
        abort_if($commission->isAnnulee(), 422, 'Cette commission est annulée.');
        abort_if($part->isVersee(), 422, 'Cette part est déjà entièrement versée.');

        // ── Frais véhicule : déduction automatique au 1er versement ─────────────
        $fraisAAppliquer = 0.0;
        $totalFraisVehicule = 0.0;
        $vehiculeAvecFrais = null;

        if ($part->type_beneficiaire === 'proprietaire' && $part->versements()->doesntExist()) {
            $vehicule = $commission->vehicule?->load('frais');
            if ($vehicule) {
                $totalFraisVehicule = (float) $vehicule->frais->sum('montant');
                if ($totalFraisVehicule > 0) {
                    $fraisAAppliquer = min($totalFraisVehicule, (float) $part->montant_brut);
                    $vehiculeAvecFrais = $vehicule;
                }
            }
        }

        // Restant effectif après déduction anticipée des frais
        $restant = $fraisAAppliquer > 0
            ? max(0.0, (float) $part->montant_brut - $fraisAAppliquer - (float) $part->montant_verse)
            : $part->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$restant}"],
            'date_versement' => 'required|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => 'Le montant dépasse le restant dû.',
            'date_versement.required' => 'La date est obligatoire.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
        ]);

        DB::transaction(function () use ($part, $vehiculeAvecFrais, $fraisAAppliquer, $data, $commission) {
            // 1. Appliquer les frais à la part (1 seule fois, premier versement)
            if ($fraisAAppliquer > 0 && $vehiculeAvecFrais) {
                $fraisList = $vehiculeAvecFrais->frais;
                $types = $fraisList->pluck('type')->unique();
                $typePrincipal = $types->count() === 1 ? $types->first() : 'autre';
                $commentairePrincipal = ($typePrincipal === 'autre')
                    ? ($fraisList->count() === 1 ? $fraisList->first()->commentaire : 'Frais véhicule')
                    : null;

                $part->appliquerFrais($fraisAAppliquer, $typePrincipal, $commentairePrincipal);

                // 2. Consommer les frais — supprimer tous, recréer un reliquat si nécessaire
                $reliquat = max(0.0, round($totalFraisVehicule - $fraisAAppliquer, 2));
                $vehiculeAvecFrais->frais()->delete();
                if ($reliquat > 0) {
                    $vehiculeAvecFrais->frais()->create([
                        'montant' => $reliquat,
                        'type' => $typePrincipal,
                        'commentaire' => $typePrincipal === 'autre' ? 'Reliquat frais' : null,
                    ]);
                }
            }

            // 3. Enregistrer le versement
            $part->versements()->create($data);

            // 4. Recalcul statut part → commission
            $part->recalculStatut();

            // 5. Auto-clôture commande si facture payée et toutes commissions versées
            $commission->commande?->cloturerSiComplete();
        });

        return redirect()
            ->route('commissions.show', $commission)
            ->with('success', 'Versement enregistré.');
    }

    /**
     * Supprime un versement.
     * Route : DELETE /versements-commissions/{versement}
     */
    public function destroy(VersementCommission $versement_commission): RedirectResponse
    {
        $commission = $versement_commission->part->commission;
        abort_unless($commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');

        $versement_commission->delete();

        return redirect()
            ->route('commissions.show', $commission)
            ->with('success', 'Versement supprimé.');
    }
}
