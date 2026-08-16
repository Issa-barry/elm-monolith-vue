<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Logistique\SaisirQuantitesChargeesRequest;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\Livreur;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SaisirQuantitesChargeesController extends Controller
{
    public function __invoke(SaisirQuantitesChargeesRequest $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->when($user->telephone, fn (Builder $q2) => $q2->orWhereHas('personne', fn (Builder $p) => $p->where('telephone', $user->telephone))))
            ->first();

        if (! $livreur || ! $livreur->equipes()->pluck('equipes_livraison.id')->contains($transfert->equipe_livraison_id)) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($transfert->statut !== StatutTransfert::CHARGEMENT) {
            return response()->json(['message' => 'Le transfert n\'est pas en chargement.'], 422);
        }

        $lignesData = collect($request->validated()['lignes'])->keyBy('id');

        DB::transaction(function () use ($transfert, $lignesData) {
            $transfert->lignes()->each(function (TransfertLigne $ligne) use ($lignesData) {
                if ($lignesData->has($ligne->id)) {
                    $ligne->update(['quantite_chargee' => $lignesData[$ligne->id]['quantite_chargee']]);
                }
            });
        });

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.variante:id,produit_id,sku',
            // image_url est un accesseur (dérivé de produit_medias, pas une colonne) : on charge
            // la relation medias plutôt que de la lister dans un select() limité aux colonnes.
            'lignes.variante.produit:id,nom',
            'lignes.variante.produit.medias',
        ]);

        return response()->json(new TransfertResource($transfert->fresh()));
    }
}
