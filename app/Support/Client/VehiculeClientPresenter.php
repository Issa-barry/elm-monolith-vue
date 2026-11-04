<?php

namespace App\Support\Client;

use App\Enums\ActiviteCompte;
use App\Models\EquipeLivreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Services\Client\Data\VehiculeRattache;
use App\Services\Commission\PartParrainService;
use Illuminate\Support\Collection;

/**
 * Mise en forme d'un véhicule pour l'espace client, partagée par `GET /v1/mobile/vehicules/mine`
 * (contrat de l'application mobile, inchangé) et `GET /v1/mobile/vehicules/rattaches` (ADR 0054).
 * Extrait tel quel de VehiculesController.
 */
class VehiculeClientPresenter
{
    /** Relations à charger pour complet(). */
    public const RELATIONS = [
        'typeVehicule',
        'equipe.membres.livreur.personne',
        'proprietaire.personne',
        'capacites.categorie',
    ];

    /**
     * Véhicule ouvert par un lien propriétaire ou livreur : toutes les informations.
     *
     * @param  Collection<string, int>  $enTransit  vehicule_id => index, véhicules en transit.
     */
    public function complet(Vehicule $v, Collection $enTransit, ?Proprietaire $proprietaire): array
    {
        return [
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
            'photo_url' => $this->photoUrl($v),
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
        ];
    }

    /**
     * Ligne de `vehicules/rattaches` (ADR 0054). Un véhicule seulement parrainé est en lecture
     * restreinte (décision D1 du 03/11/2026) : identité du véhicule et nom du propriétaire, jamais
     * ses coordonnées, l'équipe, les capacités ni l'état de livraison. Mêmes clés dans les deux cas.
     *
     * @param  Collection<string, int>  $enTransit
     */
    public function rattache(VehiculeRattache $r, Collection $enTransit, ?Proprietaire $proprietaire): array
    {
        $v = $r->vehicule;
        $complet = $r->accesOperationnel();

        return [
            'id' => $v->id,
            'nom' => $v->nom_vehicule,
            'immatriculation' => $v->immatriculation,
            'type' => $v->type_label,
            'statut' => $v->is_active ? 'actif' : 'inactif',
            'is_active' => (bool) $v->is_active,
            'photo_url' => $this->photoUrl($v),
            'liens' => $r->liensValeurs(),
            'lecture_restreinte' => ! $complet,
            'en_livraison' => $complet ? isset($enTransit[$v->id]) : null,
            'role' => match (true) {
                $r->a(ActiviteCompte::PROPRIETAIRE) => ActiviteCompte::PROPRIETAIRE->value,
                $r->a(ActiviteCompte::LIVREUR) => ActiviteCompte::LIVREUR->value,
                default => ActiviteCompte::PARRAIN->value,
            },
            'proprietaire' => $complet ? $this->proprietaireData($v) : $this->proprietaireNom($v),
            'equipe' => $complet ? $this->equipeData($v) : [],
            'capacites' => $complet ? $this->capacitesData($v) : [],
            'remuneration_parrain' => $r->a(ActiviteCompte::PARRAIN) ? $this->remunerationParrain($v) : [],
        ];
    }

    private function photoUrl(Vehicule $v): ?string
    {
        return $v->photo_path
            ? request()->getSchemeAndHttpHost().'/api/vehicules/'.$v->id.'/photo'
            : null;
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

    /** Lecture restreinte : le nom seul, pour identifier le véhicule ; ni identifiant ni coordonnées. */
    private function proprietaireNom(Vehicule $v): ?array
    {
        if (! $v->proprietaire) {
            return null;
        }

        return [
            'id' => null,
            'nom_complet' => $v->proprietaire->nom_complet,
            'telephone' => null,
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
                // nom_complet peut être une désignation provisoire ("Chauffeur-1") : le frontend
                // préfère prénom + nom quand les deux sont connus.
                'prenom' => $membre->livreur->prenom,
                'nom' => $membre->livreur->nom,
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

    /** Part du parrain en vigueur sur le véhicule, comme l'espace parrain (VehiculesParrainController). */
    private function remunerationParrain(Vehicule $v): array
    {
        return collect(PartParrainService::remuneration($v))->map(fn (array $part) => [
            'processus_label' => $part['processus_label'],
            'categorie_nom' => $part['categorie_nom'],
            'montant' => $part['montant'],
            'a_definir' => $part['a_repartir'],
            'depuis' => $part['depuis'],
        ])->values()->all();
    }
}
