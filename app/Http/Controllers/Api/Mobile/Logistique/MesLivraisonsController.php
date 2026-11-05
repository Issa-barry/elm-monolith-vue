<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Logistique\LivreurCompteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MesLivraisonsController extends Controller
{
    public function __construct(private readonly LivreurCompteResolver $livreurs) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $livreur = $this->livreurs->pourCompte($user);

        if (! $livreur) {
            return response()->json([]);
        }

        $equipeIds = $livreur->equipes()->pluck('equipes_livraison.id');

        if ($equipeIds->isEmpty()) {
            return response()->json([]);
        }

        $tab = $request->query('tab', 'en_cours');

        $statutsEnCours = [StatutTransfert::BROUILLON->value, StatutTransfert::CHARGEMENT->value, StatutTransfert::TRANSIT->value];
        $statutsHistorique = [StatutTransfert::RECEPTION->value, StatutTransfert::CLOTURE->value, StatutTransfert::ANNULE->value, StatutTransfert::RETOURNE->value];

        $statuts = $tab === 'historique' ? $statutsHistorique : $statutsEnCours;

        $transferts = TransfertLogistique::query()
            ->with([
                'siteSource:id,nom',
                'siteDestination:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
                'lignes',
            ])
            ->whereIn('statut', $statuts)
            ->whereIn('equipe_livraison_id', $equipeIds)
            ->where('organization_id', $livreur->organization_id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(TransfertResource::collection($transferts));
    }
}
