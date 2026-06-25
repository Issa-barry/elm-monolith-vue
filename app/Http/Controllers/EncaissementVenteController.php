<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Features\ModuleFeature;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Services\AuditLogService;
use App\Services\CashbackService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class EncaissementVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function store(Request $request, FactureVente $facture_vente): RedirectResponse
    {
        abort_if($facture_vente->isAnnulee(), 422, 'Cette facture est annulee.');
        abort_unless(
            $facture_vente->organization_id === auth()->user()->organization_id,
            403,
            'Acces refuse.'
        );

        $commande = $facture_vente->commande;
        abort_unless(
            ! $commande || $commande->isEncaissable(),
            422,
            'Le chargement doit être validé avant tout encaissement ou paiement.'
        );

        $montantRestant = $facture_vente->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            'date_encaissement' => 'nullable|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit etre superieur a 0.',
            'montant.max' => 'Le montant ne peut pas depasser le restant du.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
        ]);

        $data['date_encaissement'] ??= now()->toDateString();

        $facture_vente->encaissements()->create([
            'montant' => $data['montant'],
            'date_encaissement' => $data['date_encaissement'],
            'mode_paiement' => $data['mode_paiement'],
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        // Audit: log on the parent commande
        if ($commande) {
            $this->auditService->record(
                $commande,
                AuditEvent::ENCAISSEMENT_ADDED,
                auth()->user(),
                null,
                [
                    'montant' => (float) $data['montant'],
                    'mode_paiement' => $data['mode_paiement'],
                    'date_encaissement' => $data['date_encaissement'],
                ],
            );
        }

        $etaitPayee = $facture_vente->isPayee();
        $facture_vente->recalculStatut();
        $estPayeeMaintenant = $facture_vente->isPayee();

        // Auto-transition LIVRAISON_EN_COURS → LIVREE au premier encaissement.
        if ($commande?->isLivraisonEnCours()) {
            CommandeVenteService::passerEnLivree($commande);
            CommandeVenteActiviteService::log($commande, 'livree');
        }

        // Auto-clôture si LIVREE + facture payée + commissions versées.
        $commande?->cloturerSiComplete();

        // Cashback: declenche uniquement quand la facture passe a "payee".
        if (! $etaitPayee && $estPayeeMaintenant) {
            if ($commande && $commande->organization_id && $commande->client_id) {
                $org = Organization::find($commande->organization_id);
                if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
                    app(CashbackService::class)->processVente($commande);
                }
            }
        }

        return redirect()->back()->with('success', 'Encaissement enregistre.');
    }

    public function destroy(EncaissementVente $encaissement_vente): RedirectResponse
    {
        $facture = $encaissement_vente->facture;

        abort_unless(
            $facture && $facture->organization_id === auth()->user()->organization_id,
            403,
            'Acces refuse.'
        );
        abort_if($facture->isAnnulee(), 422, 'Impossible de modifier une facture annulee.');

        // Audit: log on the parent commande before deletion
        $commande = $facture->commande;
        if ($commande) {
            $this->auditService->record(
                $commande,
                AuditEvent::ENCAISSEMENT_DELETED,
                auth()->user(),
                [
                    'montant' => (float) $encaissement_vente->montant,
                    'mode_paiement' => $encaissement_vente->mode_paiement?->value,
                    'date_encaissement' => $encaissement_vente->date_encaissement?->toDateString(),
                ],
                null,
            );
        }

        $encaissement_vente->delete();
        $facture->recalculStatut();

        return redirect()->back()->with('success', 'Encaissement supprime.');
    }
}
