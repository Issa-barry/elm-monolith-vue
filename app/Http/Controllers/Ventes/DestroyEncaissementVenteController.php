<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\EncaissementVente;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class DestroyEncaissementVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function __invoke(EncaissementVente $encaissement_vente): RedirectResponse
    {
        // Jusqu'au 24/09/2026 cette route n'exigeait AUCUNE permission (seule l'organisation était
        // contrôlée) : tout utilisateur du module Ventes pouvait supprimer un encaissement par une
        // requête directe. Supprimer un encaissement contrepasse de l'argent reçu — même niveau
        // d'exception que l'annulation exceptionnelle d'une commande, même permission.
        abort_unless(auth()->user()->can('ventes.annuler_exceptionnel'), 403, 'Action non autorisee.');

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
        // pour toute suppression, quel que soit l'appelant — même raison que
        // StoreEncaissementVenteController ci-dessus (éviter un double déclenchement de la
        // génération de commission).
        $encaissement_vente->delete();

        return redirect()->back()->with('success', 'Encaissement supprime.');
    }
}
