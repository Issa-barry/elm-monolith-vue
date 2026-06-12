<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Logistique\SaisirQuantitesRecuesRequest;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SaisirReceptionController extends Controller
{
    public function __invoke(SaisirQuantitesRecuesRequest $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $transfert->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($transfert->statut !== StatutTransfert::TRANSIT) {
            return response()->json(['message' => 'Seul un transfert en transit peut être réceptionné.'], 422);
        }

        $lignesData = collect($request->validated()['lignes'])->keyBy('id');

        DB::transaction(function () use ($transfert, $lignesData) {
            $transfert->lignes()->each(function (TransfertLigne $ligne) use ($lignesData) {
                if ($lignesData->has($ligne->id)) {
                    $data = $lignesData[$ligne->id];
                    $ligne->update([
                        'quantite_recue' => $data['quantite_recue'],
                        'ecart_type' => $data['ecart_type'],
                        'ecart_motif' => $data['ecart_motif'] ?? null,
                    ]);
                }
            });
        });

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,nom',
            'lignes.produit:id,nom,code_interne,image_url',
            'commission.parts',
        ]);

        return response()->json(new TransfertResource($transfert->fresh()));
    }
}
