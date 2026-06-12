<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MesLivraisonsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->when($user->telephone, fn (Builder $q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        if (! $livreur) {
            return response()->json([]);
        }

        $equipeIds = $livreur->equipes()->pluck('equipes_livraison.id');

        if ($equipeIds->isEmpty()) {
            return response()->json([]);
        }

        $tab = $request->query('tab', 'en_cours');

        $statutsEnCours   = [StatutTransfert::BROUILLON->value, StatutTransfert::CHARGEMENT->value, StatutTransfert::TRANSIT->value];
        $statutsHistorique = [StatutTransfert::RECEPTION->value, StatutTransfert::CLOTURE->value, StatutTransfert::ANNULE->value];

        $statuts = $tab === 'historique' ? $statutsHistorique : $statutsEnCours;

        $transferts = TransfertLogistique::query()
            ->with([
                'siteSource:id,nom',
                'siteDestination:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'equipeLivraison:id,nom',
                'lignes',
            ])
            ->whereIn('statut', $statuts)
            ->whereIn('equipe_livraison_id', $equipeIds)
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(TransfertResource::collection($transferts));
    }
}
