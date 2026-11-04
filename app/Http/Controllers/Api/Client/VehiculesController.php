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
use App\Support\Client\VehiculeClientPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VehiculesController extends Controller
{
    #[Endpoint(
        description: 'Liste des véhicules du proprietaire (les siens) ou du livreur (ceux de son '
            .'équipe) — jamais toute la flotte de l\'organisation. **Aucun statut "Entretien"/'
            .'maintenance n\'existe dans le modèle ELM** — seul `is_active` (booléen) existe ; '
            .'n\'affichez pas un statut de ce type sans colonne backend dédiée (elle n\'existe pas). '
            .'`capacite`/`conducteur` sont des champs hérités conservés pour compatibilité '
            .'descendante — la source canonique est désormais `capacites[]` (une entrée par '
            .'catégorie, cf. VehiculeCapacite) et `equipe[]` (tous les membres actifs, avec '
            .'`role` = valeur réelle stockée en base : `chauffeur`/`convoyeur`, jamais traduite ; '
            .'`nom_complet` peut être une désignation provisoire comme `Chauffeur-1`, à remplacer '
            .'à l\'affichage par `prenom` + `nom` quand les deux sont renseignés). '
            .'`proprietaire` est `null` si le véhicule n\'a aucun propriétaire renseigné. '
            .'Les véhicules parrainés n\'y figurent pas : cf. `GET /v1/mobile/vehicules/rattaches`.',
    )]
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver, VehiculeClientPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $identityResolver->resolve($user);
        $organizationId = $identity->organizationId;
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        $vehicules = $this->vehiculesPartenaires($organizationId, $proprietaire, $livreur);
        $enTransit = self::enTransit($user);

        return response()->json(
            $vehicules->map(fn (Vehicule $v) => $presenter->complet($v, $enTransit, $proprietaire))->values()
        );
    }

    /**
     * IDs des véhicules actuellement en transit.
     *
     * @return Collection<string, int>
     */
    public static function enTransit(User $user): Collection
    {
        return TransfertLogistique::query()
            ->where('statut', StatutTransfert::TRANSIT->value)
            ->whereNotNull('vehicule_id')
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->pluck('vehicule_id')
            ->flip();
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
            ->with(VehiculeClientPresenter::RELATIONS)
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
