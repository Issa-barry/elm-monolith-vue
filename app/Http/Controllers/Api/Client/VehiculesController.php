<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VehiculesController extends Controller
{
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $identityResolver->resolve($user);
        $organizationId = $identity->organizationId;
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        $vehicules = $this->vehiculesPartenaires($organizationId, $proprietaire, $livreur);

        // IDs des véhicules actuellement en transit
        $enTransit = TransfertLogistique::query()
            ->where('statut', StatutTransfert::TRANSIT->value)
            ->whereNotNull('vehicule_id')
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->pluck('vehicule_id')
            ->flip();

        return response()->json(
            $vehicules->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'type' => $v->type_label,
                // Colonne héritée, jamais alimentée par les parcours actuels (capacité portée
                // par vehicule_capacites désormais, cf. VehiculeCapaciteService) — contrat API
                // mobile conservé tel quel (nombre unique), sans repli sur le type.
                'capacite' => $v->capacite_packs,
                'is_active' => (bool) $v->is_active,
                'photo_url' => $v->photo_path
                                    ? request()->getSchemeAndHttpHost().'/api/vehicules/'.$v->id.'/photo'
                                    : null,
                'en_livraison' => isset($enTransit[$v->id]),
                'role' => $proprietaire && $v->proprietaire_id === $proprietaire->id
                                    ? 'proprietaire'
                                    : 'livreur',
            ])->values()
        );
    }

    /** @return Collection<int, Vehicule> */
    private function vehiculesPartenaires(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return Vehicule::query()
            ->with('typeVehicule')
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere('proprietaire_id', $proprietaire->id);
                }
                if ($livreur !== null) {
                    $query->orWhereHas(
                        'equipe.membres',
                        fn ($sq) => $sq->where('livreur_id', $livreur->id)
                    );
                }
            })
            ->orderBy('nom_vehicule')
            ->get();
    }
}
