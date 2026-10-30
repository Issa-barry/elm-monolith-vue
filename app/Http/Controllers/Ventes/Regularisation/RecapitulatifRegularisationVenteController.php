<?php

namespace App\Http\Controllers\Ventes\Regularisation;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\RegularisationVente;
use App\Services\Ventes\Regularisation\RegularisationVenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Récapitulatif avant/après d'une régularisation après encaissement (ADR 0043) pour les quantités
 * saisies : facture, excédent, encaissements réduits, stock, commissions et écarts, refus
 * éventuels et empreinte — renvoyée telle quelle à la demande de code puis à la confirmation.
 */
class RecapitulatifRegularisationVenteController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, RegularisationVenteService $service): JsonResponse
    {
        $this->authorize('regulariserApresEncaissement', $commande_vente);
        // Explicite : le Gate::before du super admin court-circuite la Policy.
        abort_unless($commande_vente->organization_id === $request->user()->organization_id, 403, 'Accès refusé.');

        $data = $request->validate(self::reglesQuantites());

        return response()->json($service->recapitulatif($commande_vente, $data['lignes'], $data['traitement_excedent'] ?? null));
    }

    /** @return array<string, mixed> */
    public static function reglesQuantites(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0'],
            'traitement_excedent' => ['nullable', Rule::in([RegularisationVente::EXCEDENT_NON_RECU, RegularisationVente::EXCEDENT_A_REMBOURSER])],
        ];
    }

    /** @return array<string, mixed> */
    public static function reglesConfirmation(): array
    {
        return self::reglesQuantites() + [
            'motif' => ['required', 'string', 'min:'.RegularisationVenteService::MOTIF_LONGUEUR_MIN, 'max:2000'],
            'empreinte' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'lignes.required' => 'Indiquez les quantités corrigées.',
            'lignes.*.quantite.integer' => 'Les quantités sont des nombres entiers.',
            'lignes.*.quantite.min' => 'Une quantité ne peut pas être négative.',
            'motif.required' => 'Le motif est obligatoire.',
            'motif.min' => 'Le motif doit faire au moins '.RegularisationVenteService::MOTIF_LONGUEUR_MIN.' caractères.',
            'motif.max' => 'Le motif ne peut pas dépasser 2000 caractères.',
            'empreinte.required' => 'Rouvrez la régularisation pour recharger le récapitulatif.',
            'empreinte.size' => 'Rouvrez la régularisation pour recharger le récapitulatif.',
        ];
    }
}
