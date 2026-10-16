<?php

namespace App\Http\Controllers\Logistique;

use App\Enums\MotifRetourTransfert;
use App\Http\Controllers\Controller;
use App\Models\TransfertLogistique;
use App\Services\TransfertRetourService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnregistrerRetourTransfertController extends Controller
{
    /**
     * Enregistrer le retour au site source de tout ou partie de la marchandise partie sur un
     * transfert (ADR 0029, cf. TransfertRetourService) — l'enregistrement vaut validation. Retour
     * total avant réception : le transfert passe en « Retourné ».
     */
    public function __invoke(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('enregistrerRetour', $transfert_logistique);

        $data = $request->validate([
            'motif' => ['required', Rule::enum(MotifRetourTransfert::class)],
            'commentaire' => ['nullable', 'string', 'max:1000', 'required_if:motif,'.MotifRetourTransfert::AUTRE->value],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0'],
        ], [
            'motif.required' => 'Le motif du retour est obligatoire.',
            'motif.enum' => 'Motif de retour invalide.',
            'commentaire.required_if' => 'Précisez la raison du retour.',
            'lignes.required' => 'Indiquez au moins une quantité retournée.',
            'lignes.*.quantite.integer' => 'Les quantités retournées doivent être des nombres entiers.',
        ]);

        try {
            $retour = TransfertRetourService::enregistrer(
                $transfert_logistique,
                $data['lignes'],
                MotifRetourTransfert::from($data['motif']),
                $data['commentaire'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', $retour->retour_total
                ? 'Retour total enregistré : toute la marchandise est réintégrée au site source et le transfert est retourné.'
                : 'Retour enregistré : la marchandise est réintégrée au stock du site source.');
    }
}
