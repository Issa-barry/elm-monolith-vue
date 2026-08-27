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
            .'`capacite` est un champ hérité (nombre unique, packs) — la capacité réelle '
            .'multi-catégorie n\'est pas exposée ici. `conducteur` : nom du membre d\'équipe au rôle '
            .'`chauffeur`, `null` si aucune équipe ou aucun chauffeur assigné (jamais le premier '
            .'membre pris au hasard).',
    )]
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
                // Pas de notion de statut "entretien"/maintenance dans le modèle ELM — seul
                // is_active existe. Un statut plus fin (ex: "En panne") nécessiterait une
                // colonne dédiée, volontairement pas ajoutée ici sans besoin métier confirmé.
                'photo_url' => $v->photo_path
                                    ? request()->getSchemeAndHttpHost().'/api/vehicules/'.$v->id.'/photo'
                                    : null,
                'en_livraison' => isset($enTransit[$v->id]),
                'role' => $proprietaire && $v->proprietaire_id === $proprietaire->id
                                    ? 'proprietaire'
                                    : 'livreur',
                'conducteur' => $this->conducteurNom($v),
            ])->values()
        );
    }

    /**
     * Nom du chauffeur assigné à l'équipe du véhicule, si une équipe existe et
     * qu'un membre y tient le rôle "chauffeur" — jamais un simple premier membre
     * pris au hasard (une équipe peut n'avoir que des convoyeurs).
     */
    private function conducteurNom(Vehicule $v): ?string
    {
        $chauffeur = $v->equipe?->membres->firstWhere('role', 'chauffeur');

        return $chauffeur?->livreur?->libelleAffichage();
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
            ->with(['typeVehicule', 'equipe.membres.livreur.personne'])
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
