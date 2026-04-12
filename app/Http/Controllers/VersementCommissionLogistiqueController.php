<?php

namespace App\Http\Controllers;

use App\Models\CommissionLogistiquePart;
use App\Services\CommissionLogistiqueService;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enregistrement de versements sur une part de commission logistique.
 */
class VersementCommissionLogistiqueController extends Controller
{
    private const MODES_PAIEMENT = ['especes', 'virement', 'cheque', 'mobile_money'];

    /**
     * POST /commissions-logistique/parts/{part}/versements
     */
    public function store(Request $request, CommissionLogistiquePart $part): RedirectResponse
    {
        // Vérification cross-org et autorisation via le transfert parent
        $commission = $part->commission()->with('transfert')->firstOrFail();
        $transfert = $commission->transfert;

        abort_unless(
            $transfert->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $this->authorize('verserCommission', $transfert);

        abort_if($part->isVersee(), 422, 'Cette part est déjà entièrement versée.');

        $montantMax = (float) $part->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1', "max:{$montantMax}"],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => "Le montant ne peut pas dépasser le restant dû ({$montantMax} GNF).",
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
        ]);

        // La date est toujours la date du serveur — jamais saisie par l'utilisateur.
        $dateVersement = now()->toDateString();

        CommissionLogistiqueService::verser(
            $part,
            (float) $data['montant'],
            $dateVersement,
            $data['mode_paiement'],
            $data['note'] ?? null,
        );

        TransfertActiviteService::log($transfert, 'versement_effectue', [
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'beneficiaire' => $part->beneficiaire_nom,
        ]);

        // Clôture automatique si toutes les commissions sont désormais versées
        $commission->refresh();
        if ($transfert->isReception() && $commission->isVersee()) {
            TransfertLogistiqueService::cloturerAutomatiquement($transfert);
            TransfertActiviteService::log($transfert, 'cloture');

            // Rediriger vers le transfert pour notifier la clôture automatique
            return redirect()->route('logistique.show', $transfert)
                ->with('success', 'Versement enregistré. Le transfert a été clôturé automatiquement.');
        }

        // Cas standard : retour sur la page d'origine (transfert détail ou commission détail)
        return back()->with('success', 'Versement enregistré.');
    }
}
