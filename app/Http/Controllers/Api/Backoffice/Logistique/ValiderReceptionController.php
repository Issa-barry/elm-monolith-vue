<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ValiderReceptionController extends Controller
{
    public function __invoke(Request $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $transfert->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($transfert->statut !== StatutTransfert::TRANSIT) {
            return response()->json(['message' => 'Seul un transfert en transit peut être réceptionné.'], 422);
        }

        try {
            $transfert = TransfertLogistiqueService::avancerStatut($transfert);
        } catch (ValidationException $e) {
            return response()->json(['message' => implode(' ', $e->errors()['statut'] ?? [])], 422);
        }

        TransfertActiviteService::log($transfert, 'reception_validee', [], $user->id);

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,nom',
            'lignes.produit:id,nom,code_interne,image_url',
            'commission.parts',
        ]);

        return response()->json(new TransfertResource($transfert));
    }
}
