<?php

namespace App\Http\Controllers\Api\Mobile\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DemarrerChargementController extends Controller
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

        if ($transfert->statut !== StatutTransfert::BROUILLON) {
            return response()->json(['message' => 'Le transfert n\'est pas en brouillon.'], 422);
        }

        try {
            $transfert = TransfertLogistiqueService::avancerStatut($transfert);
        } catch (ValidationException $e) {
            return response()->json(['message' => implode(' ', $e->errors()['statut'] ?? [])], 422);
        }

        TransfertActiviteService::log($transfert, 'chargement_demarre', [], $user->id);

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.produit:id,nom,code_interne,image_url',
        ]);

        return response()->json(new TransfertResource($transfert));
    }

    private function livreurPeutAcceder(Livreur $livreur, TransfertLogistique $transfert): bool
    {
        return $livreur->equipes()->pluck('equipes_livraison.id')->contains($transfert->equipe_livraison_id);
    }
}
