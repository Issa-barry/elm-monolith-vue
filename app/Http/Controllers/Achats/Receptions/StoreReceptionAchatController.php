<?php

namespace App\Http\Controllers\Achats\Receptions;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\ReceptionAchatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreReceptionAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, ReceptionAchatService $service): RedirectResponse
    {
        $this->authorize('receptionner', $achat);

        $data = $request->validate([
            'date_reception' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.qte_recue' => ['required', 'integer', 'min:0'],
        ], [
            'date_reception.required' => 'La date de réception est obligatoire.',
            'date_reception.before_or_equal' => 'La date de réception ne peut pas être dans le futur.',
            'lignes.required' => 'Saisissez au moins une quantité reçue.',
            'lignes.*.qte_recue.min' => 'La quantité reçue ne peut pas être négative.',
        ]);

        ['reception' => $reception, 'avertissements' => $avertissements] = $service->receptionner($achat, $request->user(), $data);

        $redirect = back()->with('success', "Réception {$reception->reference} enregistrée. Le stock de l'agence a été mis à jour.");

        return $avertissements === [] ? $redirect : $redirect->with('warning', implode(' ', $avertissements));
    }
}
