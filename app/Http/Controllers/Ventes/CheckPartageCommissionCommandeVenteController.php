<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\NatureOperation;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckPartageCommissionCommandeVenteController extends Controller
{
    public function __construct(private readonly CommandeVenteFormBuilder $formBuilder) {}

    /**
     * Aperçu, AVANT soumission, du blocage « partage de commission non conforme » (décision du
     * 24/09/2026) pour le véhicule et les produits en cours de saisie — rejoue exactement le
     * contrôle de création (ensurePartageLivraisonCategorieConfigure()), jamais une seconde
     * logique. Purement informatif : le vrai refus reste celui de StoreCommandeVenteController/
     * UpdateCommandeVenteController, même si cet aperçu n'a pas été consulté.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'vehicule_id' => ['required', 'string'],
            'client_id' => ['nullable', 'string'],
            'nature_operation' => ['nullable', Rule::enum(NatureOperation::class)],
            'produit_ids' => ['nullable', 'array'],
            'produit_ids.*' => ['string'],
        ]);

        $vehicule = $this->formBuilder->resolveVehiculeAvecEquipe($data['vehicule_id'], $orgId);
        if (! $vehicule) {
            return response()->json(['bloquant' => false, 'message' => null]);
        }

        $client = $this->formBuilder->resolveClientForTarification($data['client_id'] ?? null);
        $natureOperation = isset($data['nature_operation'])
            ? NatureOperation::from($data['nature_operation'])
            : NatureOperation::deriverParDefaut($client?->type, $vehicule);

        try {
            $this->formBuilder->ensurePartageLivraisonCategorieConfigure(
                $natureOperation,
                $vehicule,
                array_map(fn (string $id) => ['produit_id' => $id], $data['produit_ids'] ?? []),
                $client?->type,
                $this->formBuilder->deriverModeRemiseGrossiste($vehicule->id, $client),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'bloquant' => true,
                'message' => collect($e->errors())->flatten()->first(),
            ]);
        }

        return response()->json(['bloquant' => false, 'message' => null]);
    }
}
