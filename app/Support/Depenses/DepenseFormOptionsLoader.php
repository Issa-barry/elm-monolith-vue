<?php

namespace App\Support\Depenses;

use App\Models\Client;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Support\Collection;

/**
 * Options de formulaire (types/véhicules/sites/bénéficiaires) communes aux pages Create et Edit
 * du module Dépenses — extrait de DepenseController::load*(), partagé à l'identique par les deux
 * actions (seule la liste "sites" reste propre à chaque contrôleur : admin voit tous les sites de
 * l'organisation, un utilisateur non-admin ne voit que ses sites rattachés — ce bloc était déjà
 * dupliqué tel quel entre create() et edit() avant cette extraction, jamais mutualisé, donc laissé
 * inchangé pour ne pas mêler un changement de comportement à ce refactoring pur).
 */
final class DepenseFormOptionsLoader
{
    public static function types(string $orgId): Collection
    {
        return DepenseType::where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'libelle', 'categorie', 'commentaire_obligatoire', 'justificatif_obligatoire'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'libelle' => $t->libelle,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'impact_message' => $t->categorie->impactMessage(),
                'commentaire_obligatoire' => $t->commentaire_obligatoire,
                'justificatif_obligatoire' => $t->justificatif_obligatoire,
            ]);
    }

    public static function vehicules(string $orgId): Collection
    {
        return Vehicule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->with(['site:id,nom', 'proprietaire:id,personne_id', 'proprietaire.personne'])
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation', 'categorie', 'site_id', 'proprietaire_id'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                // Propriété réelle du véhicule — plus jamais reconstruite depuis
                // livraison_logistique (confusion usage/propriété corrigée, cf. Vehicule::categorie).
                // Consommé par Depenses/Create.vue et Edit.vue (vehiculeContext) pour distinguer
                // "ELM" d'un propriétaire tiers dans le picker.
                'categorie' => $v->categorie->value,
                'site_nom' => $v->site?->nom,
                'proprietaire_nom' => $v->proprietaire
                    ? trim("{$v->proprietaire->prenom} {$v->proprietaire->nom}")
                    : null,
                'has_proprietaire' => (bool) $v->proprietaire_id,
            ]);
    }

    public static function sites(string $orgId): Collection
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    public static function employes(string $orgId): Collection
    {
        return Employe::with('personne')
            ->where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->get(['id', 'personne_id', 'matricule', 'site_id'])
            ->sortBy('nom')
            ->map(fn ($e) => [
                'id' => $e->id,
                'nom_complet' => trim("{$e->prenom} {$e->nom}"),
                'matricule' => $e->matricule,
                'telephone' => $e->telephone,
                'site_nom' => $e->site?->nom,
            ])
            ->values();
    }

    public static function livreurs(string $orgId): Collection
    {
        return Livreur::with(['personne', 'equipes.vehicule'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_complet')
            ->get(['id', 'personne_id', 'nom_complet'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'nom_complet' => $l->libelleAffichage(),
                'telephone' => $l->telephone,
                // Permet de retrouver un livreur par le nom/l'immatriculation du véhicule
                // auquel il est rattaché (Depenses/Create.vue et Edit.vue), en plus de son
                // propre nom/téléphone — deux champs distincts, pas un libellé combiné, pour
                // que le picker propose un champ de recherche dédié par critère.
                ...self::vehiculeInfoDepuis($l->equipes->pluck('vehicule')),
            ]);
    }

    public static function proprietaires(string $orgId): Collection
    {
        return Proprietaire::with(['personne', 'vehicules'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get(['id', 'personne_id'])
            ->sortBy('nom')
            ->map(fn ($p) => [
                'id' => $p->id,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
                'telephone' => $p->telephone,
                // Idem : retrouver un propriétaire par le nom/l'immatriculation d'un véhicule
                // qui lui appartient — deux champs distincts (cf. livreurs()).
                ...self::vehiculeInfoDepuis($p->vehicules),
            ])
            ->values();
    }

    public static function prestataires(string $orgId): Collection
    {
        return Prestataire::with(['personne', 'entrepriseTierce'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get()
            ->sortBy('nom_complet')
            ->map(fn (Prestataire $p) => [
                'id' => $p->id,
                'nom_complet' => $p->nom_complet,
                'telephone' => $p->phone,
            ])
            ->values();
    }

    public static function clients(string $orgId): Collection
    {
        return Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get(['id', 'nom', 'prenom', 'nom_complet', 'telephone'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'nom_complet' => $client->nom_complet,
                'telephone' => $client->telephone,
            ])
            ->values();
    }

    /**
     * Noms et immatriculations des véhicules de la collection, chacun joint par ", " —
     * deux champs distincts (pas un libellé combiné) pour que le picker propose un champ de
     * recherche dédié par critère (cf. livreurs()/proprietaires()).
     *
     * @return array{vehicule_noms: ?string, vehicule_immatriculations: ?string}
     */
    private static function vehiculeInfoDepuis(Collection $vehicules): array
    {
        $vehicules = $vehicules->filter()->unique('id');

        return [
            'vehicule_noms' => $vehicules->pluck('nom_vehicule')->filter()->implode(', ') ?: null,
            'vehicule_immatriculations' => $vehicules->pluck('immatriculation')->filter()->implode(', ') ?: null,
        ];
    }
}
