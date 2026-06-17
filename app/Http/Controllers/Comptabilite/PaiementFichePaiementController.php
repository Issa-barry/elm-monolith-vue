<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaiementFichePaiementController extends Controller
{
    public function store(Request $request, PaiementFiche $fiche): RedirectResponse
    {
        $this->authorize('payer', $fiche);

        $restant = $fiche->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1', 'max:'.$restant],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            'date_paiement' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $fiche->organization_id,
            'site_id' => $fiche->site_id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'date_paiement' => $data['date_paiement'],
            'note' => $data['note'] ?? null,
        ]);

        $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAID, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'description' => "Paiement de {$montantFmt} GNF enregistré pour {$fiche->beneficiaire_nom}",
        ]);

        return back()->with('success', 'Paiement enregistré avec succès.');
    }

    public function destroy(PaiementFichePaiement $paiement): RedirectResponse
    {
        $fiche = $paiement->fiche;
        $this->authorize('payer', $fiche);

        $montantFmt = number_format((float) $paiement->montant, 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAYMENT_CANCELLED, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => (float) $paiement->montant,
            'description' => "Paiement de {$montantFmt} GNF annulé pour {$fiche->beneficiaire_nom}",
        ]);

        $paiement->delete();

        return back()->with('success', 'Paiement supprimé.');
    }
}
