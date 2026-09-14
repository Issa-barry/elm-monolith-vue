<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class DestroyCommandeVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function __invoke(CommandeVente $vente): RedirectResponse
    {
        $this->authorize('delete', $vente);
        abort_unless($vente->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $this->auditService->record(
            $vente,
            AuditEvent::DELETED,
            auth()->user(),
            ['reference' => $vente->reference, 'statut' => $vente->statut->value],
            null,
        );

        $vente->delete();

        return redirect()->route('ventes.index')->with('success', 'Commande supprimée.');
    }
}
