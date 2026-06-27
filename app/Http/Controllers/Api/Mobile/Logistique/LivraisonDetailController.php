<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LivraisonDetailController extends Controller
{
    public function __invoke(Request $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->when($user->telephone, fn (Builder $q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        if (! $livreur || ! $this->livreurPeutAcceder($livreur, $transfert)) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.produit:id,nom,code_interne,image_url',
            'commission.parts',
            'activites',
        ]);

        return response()->json(new TransfertResource($transfert));
    }

    private function livreurPeutAcceder(Livreur $livreur, TransfertLogistique $transfert): bool
    {
        $equipeIds = $livreur->equipes()->pluck('equipes_livraison.id');

        return $equipeIds->contains($transfert->equipe_livraison_id);
    }
}
