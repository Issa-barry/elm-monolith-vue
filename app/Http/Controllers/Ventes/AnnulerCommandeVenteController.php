<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Enums\MotifAnnulation;
use App\Enums\StatutCommandeVente;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnnulerCommandeVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        if (auth()->user()->cannot('annuler', $commande_vente)) {
            abort(403, "Vous n'êtes pas autorisé à annuler cette commande.");
        }

        $validCodes = implode(',', MotifAnnulation::validValues());

        $data = $request->validate([
            'motif_annulation_code' => ['required', 'string', "in:{$validCodes}"],
            'motif_annulation_detail' => ['nullable', 'string', 'max:2000', 'required_if:motif_annulation_code,autre'],
        ], [
            'motif_annulation_code.required' => "Le motif d'annulation est obligatoire.",
            'motif_annulation_code.in' => 'Le motif sélectionné est invalide.',
            'motif_annulation_detail.required_if' => "Veuillez préciser la raison de l'annulation.",
            'motif_annulation_detail.max' => 'La précision ne peut pas dépasser 2000 caractères.',
        ]);

        $motif = MotifAnnulation::from($data['motif_annulation_code'])
            ->toMotifString($data['motif_annulation_detail'] ?? '');

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::annuler($commande_vente, $motif);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::CANCELLED,
            auth()->user(),
            ['statut' => $oldStatut, 'motif_annulation' => null],
            ['statut' => StatutCommandeVente::ANNULEE->value, 'motif_annulation' => $motif],
        );

        CommandeVenteActiviteService::log($commande_vente, 'annulee', ['motif' => $motif]);

        return back()->with('success', 'Commande annulée.');
    }
}
