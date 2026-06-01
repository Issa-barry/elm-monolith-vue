<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VehiculesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$organizationId, $proprietaire, $livreur] = $this->resolveContext($user);

        $vehicules = $this->vehiculesPartenaires($organizationId, $proprietaire, $livreur);

        return response()->json(
            $vehicules->map(fn (Vehicule $v) => [
                'id'             => $v->id,
                'nom'            => $v->nom_vehicule,
                'immatriculation'=> $v->immatriculation,
                'type'           => $v->type_label,
                'capacite'       => $v->capacite_packs,
                'is_active'      => (bool) $v->is_active,
                'photo_url'      => $v->photo_path
                                    ? request()->getSchemeAndHttpHost().'/api/vehicules/'.$v->id.'/photo'
                                    : null,
                'role'           => $proprietaire && $v->proprietaire_id === $proprietaire->id
                                    ? 'proprietaire'
                                    : 'livreur',
            ])->values()
        );
    }

    /** @return array{0:?string,1:?Proprietaire,2:?Livreur} */
    private function resolveContext(User $user): array
    {
        $orgId     = $user->organization_id;
        $telephone = $user->telephone;

        $client = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($telephone, fn ($q2) => $q2->orWhere('telephone', $telephone)))
            ->first();

        if ($orgId === null && $client) {
            $orgId = $client->organization_id;
        }

        $proprietaire = Proprietaire::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($telephone, fn ($q2) => $q2->orWhere('telephone', $telephone)))
            ->first();

        if ($orgId === null && $proprietaire) {
            $orgId = $proprietaire->organization_id;
        }

        $livreur = Livreur::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($telephone, fn ($q2) => $q2->orWhere('telephone', $telephone)))
            ->first();

        if ($orgId === null && $livreur) {
            $orgId = $livreur->organization_id;
        }

        return [$orgId, $proprietaire, $livreur];
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
