<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AnnulationExceptionnelleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemanderCodeAnnulationExceptionnelleController extends Controller
{
    /**
     * Envoie le code de confirmation à l'adresse e-mail de l'utilisateur authentifié — jamais à une
     * autre adresse. Les erreurs (données modifiées, garde-fou, envoi impossible, délai anti-spam)
     * remontent en 422 JSON.
     */
    public function __invoke(Request $request, CommandeVente $commande_vente, AnnulationExceptionnelleService $service): JsonResponse
    {
        $this->authorize('annulerExceptionnel', $commande_vente);

        $data = $request->validate(self::regles(), self::messages());

        return response()->json(
            $service->demanderCode($commande_vente, $request->user(), $data['motif'], $data['empreinte'])
        );
    }

    /** @return array<string, list<string>> */
    public static function regles(): array
    {
        return [
            'motif' => ['required', 'string', 'min:'.AnnulationExceptionnelleService::MOTIF_LONGUEUR_MIN, 'max:2000'],
            'empreinte' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'motif.required' => 'Le motif est obligatoire.',
            'motif.min' => 'Le motif doit faire au moins '.AnnulationExceptionnelleService::MOTIF_LONGUEUR_MIN.' caractères.',
            'motif.max' => 'Le motif ne peut pas dépasser 2000 caractères.',
            'empreinte.required' => 'Rouvrez l\'annulation exceptionnelle pour recharger le récapitulatif.',
            'empreinte.size' => 'Rouvrez l\'annulation exceptionnelle pour recharger le récapitulatif.',
        ];
    }
}
