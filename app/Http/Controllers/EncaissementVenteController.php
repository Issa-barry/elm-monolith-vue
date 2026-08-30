<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\NatureOperation;
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
use Illuminate\Support\Facades\DB;
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

        // Transaction : l'encaissement, la transition de statut de la facture (donc la
        // naissance éventuelle de la commission de vente sous FACTURE_ENCAISSEE — cf.
        // FactureVente::recalculStatut()/CommissionTriggerService) et les effets en
        // cascade (auto-clôture, cashback) doivent réussir ou échouer ensemble : un
        // échec en cours de route ne doit jamais laisser une commission générée pour un
        // encaissement finalement non persisté.
        try {
            DB::transaction(function () use ($facture_vente, $commande, $data) {
                $etaitPayee = $facture_vente->isPayee();

                // Auto-transition LIVRAISON_EN_COURS → LIVREE AVANT l'encaissement :
                // EncaissementVente::created (seul point désormais responsable de
                // recalculStatut()/cloturerSiComplete(), cf. commentaire plus bas) doit
                // trouver la commande déjà en LIVREE pour pouvoir la clôturer dans la
                // foulée si tout est complet. JAMAIS pour distribution_client (décision produit
                // du 30/08/2026) : sa transition vers LIVREE exige une validation de réception
                // explicite, indépendante de tout encaissement — cf.
                // CommandeVenteService::validerReceptionDistribution(). Un encaissement reçu
                // avant la réception laisse donc la commande en LIVRAISON_EN_COURS.
                if ($commande?->isLivraisonEnCours() && $commande->nature_operation !== NatureOperation::DISTRIBUTION_CLIENT) {
                    CommandeVenteService::passerEnLivree($commande);
                    CommandeVenteActiviteService::log($commande, 'livree');
                }

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

                // recalculStatut()/cloturerSiComplete() ne sont PAS rappelés ici : le hook
                // EncaissementVente::created (app/Models/EncaissementVente.php) vient de les
                // exécuter, pour TOUTE création d'encaissement quel que soit l'appelant (ce
                // contrôleur, un import, l'API...). Un second appel ici, sur l'instance
                // $facture_vente propre à ce contrôleur (non synchronisée avec celle chargée
                // par le hook), déclenchait deux tentatives de génération de commission pour
                // le même paiement — cf. incident CMD-230826-004 (2 tentatives à 1s d'écart).
                // On se contente de rafraîchir l'état pour lire le résultat du hook.
                $facture_vente->refresh();
                $estPayeeMaintenant = $facture_vente->isPayee();

                // Cashback: declenche uniquement quand la facture passe a "payee".
                if (! $etaitPayee && $estPayeeMaintenant) {
                    if ($commande && $commande->organization_id && $commande->client_id) {
                        $org = Organization::find($commande->organization_id);
                        if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
                            app(CashbackService::class)->processVente($commande);
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Encaissement non enregistré : {$e->getMessage()}"]);
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

        // recalculStatut()/cloturerSiComplete() ne sont pas rappelés ici : le hook
        // EncaissementVente::deleted (app/Models/EncaissementVente.php) les exécute déjà
        // pour toute suppression, quel que soit l'appelant — même raison que store()
        // ci-dessus (éviter un double déclenchement de la génération de commission).
        $encaissement_vente->delete();

        return redirect()->back()->with('success', 'Encaissement supprime.');
    }
}
