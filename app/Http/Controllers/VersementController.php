<?php
namespace App\Http\Controllers;

use App\Enums\PackingStatut;
use App\Models\Packing;
use App\Models\Versement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VersementController extends Controller
{
    public function store(Request $request, Packing $packing): RedirectResponse
    {
        $this->authorize('update', $packing);
        abort_if($packing->statut === PackingStatut::ANNULEE, 403, 'Impossible d\'ajouter un versement à un packing annulé.');
        abort_if($packing->montant_restant <= 0, 422, 'Ce packing est déjà entièrement payé.');

        $data = $request->validate([
            'date'    => 'required|date',
            'montant' => 'required|integer|min:1|max:' . $packing->montant_restant,
            'notes'   => 'nullable|string|max:1000',
        ], [
            'date.required'    => 'La date est obligatoire.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min'      => 'Le montant doit être supérieur à 0.',
            'montant.max'      => "Le montant ne peut pas dépasser le restant dû ({$packing->montant_restant} GNF).",
        ]);

        $packing->versements()->create($data);

        return back()->with('success', 'Versement enregistré.');
    }

    public function destroy(Packing $packing, Versement $versement): RedirectResponse
    {
        $this->authorize('update', $packing);
        abort_if($versement->packing_id !== $packing->id, 404);
        abort_if($packing->statut === PackingStatut::ANNULEE, 403, 'Impossible de supprimer un versement d\'un packing annulé.');

        $versement->delete();

        return back()->with('success', 'Versement supprimé.');
    }
}
