<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
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
            .'`capacite`/`conducteur` sont des champs hérités conservés pour compatibilité '
            .'descendante — la source canonique est désormais `capacites[]` (une entrée par '
            .'catégorie, cf. VehiculeCapacite) et `equipe[]` (tous les membres actifs, avec '
            .'`role` = valeur réelle stockée en base : `chauffeur`/`convoyeur`, jamais traduite). '
            .'`proprietaire` est `null` si le véhicule n\'a aucun propriétaire renseigné.',
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
                'statut' => $v->is_active ? 'actif' : 'inactif',
                // Colonne héritée, jamais alimentée par les parcours actuels (capacité portée
                // par vehicule_capacites désormais, cf. VehiculeCapaciteService) — contrat API
                // mobile conservé tel quel (nombre unique), sans repli sur le type. Ne plus lire
                // cette valeur côté client : cf. `capacites[]`.
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
                // Conservé pour compatibilité descendante uniquement — ne reflète que le
                // chauffeur, jamais les autres membres. Source canonique : `equipe[]`.
                'conducteur' => $this->conducteurNom($v),
                'proprietaire' => $this->proprietaireData($v),
                'equipe' => $this->equipeData($v),
                'capacites' => $this->capacitesData($v),
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

    /** Identité minimale du propriétaire — jamais l'objet Proprietaire brut (pas de champs internes). */
    private function proprietaireData(Vehicule $v): ?array
    {
        if (! $v->proprietaire) {
            return null;
        }

        return [
            'id' => $v->proprietaire->id,
            'nom_complet' => $v->proprietaire->nom_complet,
            'telephone' => $v->proprietaire->telephone,
        ];
    }

    /**
     * Équipe complète (tous les membres actifs, pas seulement le chauffeur) — `role` est la
     * valeur réelle stockée sur equipe_livreurs (`chauffeur`/`convoyeur`), jamais traduite ici :
     * au frontend de choisir un libellé, pas au backend d'inventer une taxonomie parallèle.
     * Filtré en mémoire sur la collection déjà eager-chargée (equipe.membres.livreur.personne) :
     * aucune requête supplémentaire par véhicule.
     */
    private function equipeData(Vehicule $v): array
    {
        if (! $v->equipe) {
            return [];
        }

        return $v->equipe->membres
            ->filter(fn (EquipeLivreur $membre) => $membre->livreur?->is_active === true)
            ->map(fn (EquipeLivreur $membre) => [
                'id' => $membre->livreur->id,
                'nom_complet' => $membre->livreur->nom_complet,
                'telephone' => $membre->livreur->telephone,
                'role' => $membre->role,
                'ordre' => $membre->ordre,
            ])
            ->values()
            ->all();
    }

    /**
     * Capacité par catégorie (seule source de vérité, cf. VehiculeCapacite) — jamais un repli
     * sur `capacite_packs`/`capacite_bouteilles` (colonnes héritées, cf. `capacite` ci-dessus).
     */
    private function capacitesData(Vehicule $v): array
    {
        return $v->capacites
            ->map(fn (VehiculeCapacite $c) => [
                'categorie_id' => $c->categorie_id,
                'categorie' => $c->categorie?->nom,
                'capacite' => $c->capacite_max,
            ])
            ->values()
            ->all();
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
            ->with([
                'typeVehicule',
                'equipe.membres.livreur.personne',
                'proprietaire.personne',
                'capacites.categorie',
            ])
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
