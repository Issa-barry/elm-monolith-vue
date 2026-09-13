<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Enums\StatutCommandeVente;
use App\Http\Controllers\Controller;
use App\Jobs\NotifierLivreursCommandeVenteJob;
use App\Models\CommandeVente;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ValiderCommandeVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function __invoke(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('confirmer', $commande_vente);

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::confirmer($commande_vente);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::VALIDATED,
            auth()->user(),
            ['statut' => $oldStatut],
            ['statut' => StatutCommandeVente::A_CHARGER->value],
        );

        CommandeVenteActiviteService::log($commande_vente, 'confirmee');

        if ($commande_vente->vehicule_id) {
            NotifierLivreursCommandeVenteJob::dispatch($commande_vente->id, $commande_vente->reference);
        }

        return back()->with('success', 'Commande confirmée. En attente de chargement.');
    }
}
