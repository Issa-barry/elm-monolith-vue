<?php

namespace App\Support\Contacts;

use App\Enums\ContactCategorie;
use App\Enums\StatutEmploye;
use App\Enums\TypeProprietaire;
use App\Models\ContactSousCategorie;
use App\Models\EntrepriseTierce;
use App\Models\Personne;

/**
 * Données transmises aux écrans du référentiel Contacts (ADR 0060, lot 2). Seules les coordonnées
 * sont exposées : jamais la naissance, les pièces ou les données RH d'une Personne. Les fonctions
 * reçues ont déjà été filtrées par VisibiliteContacts : rien ici n'en ajoute.
 */
final class PresentationContacts
{
    public const LIBELLES_FONCTIONS = [
        'client' => 'Client',
        'fournisseur' => 'Fournisseur',
        'prestataire' => 'Prestataire',
        'proprietaire' => 'Propriétaire',
        'parrain' => 'Parrain',
        'livreur' => 'Livreur',
        'employe' => 'Employé',
        'interlocuteur' => 'Interlocuteur',
    ];

    /** @var array<string, string> libellés des sous-catégories de l'organisation, clé « categorie:code » */
    private array $libellesSousCategories;

    public function __construct(private readonly string $organizationId)
    {
        $this->libellesSousCategories = ContactSousCategorie::where('organization_id', $organizationId)
            ->get(['categorie', 'code', 'libelle'])
            ->mapWithKeys(fn (ContactSousCategorie $s) => ["{$s->categorie->value}:{$s->code}" => $s->libelle])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $fonctions
     * @return list<array<string, mixed>>
     */
    public function fonctions(array $fonctions): array
    {
        return array_map(fn (array $f) => [
            ...$f,
            'libelle' => self::LIBELLES_FONCTIONS[$f['fonction']] ?? $f['fonction'],
            'sous_categorie_libelle' => $this->libelleSousCategorie($f['fonction'], $f['sous_categorie'] ?? null),
            'lien' => $this->lien($f),
            // D10 (lot 5) : propriétaire entreprise affiché sur la fiche de son contact.
            'entreprise_lien' => $f['fonction'] === 'proprietaire' && isset($f['entreprise_tierce_id'])
                ? "/backoffice/contacts/entreprises/{$f['entreprise_tierce_id']}"
                : null,
        ], $fonctions);
    }

    public function nomPersonne(Personne $personne, array $fonctions): string
    {
        $nom = trim($personne->nom_complet);
        if ($nom !== '') {
            return $nom;
        }

        // Livreurs désignés sur leur fonction (« Chauffeur-1 … ») : repli sur cette désignation,
        // seulement si la fonction livreur est visible du lecteur.
        foreach ($fonctions as $f) {
            if ($f['fonction'] === 'livreur' && ! empty($f['reference'])) {
                return $f['reference'];
            }
        }

        return $personne->telephone ?: 'Personne sans nom';
    }

    /** @return array<string, mixed> */
    public function coordonneesPersonne(Personne $personne): array
    {
        return [
            'id' => $personne->id,
            'nom' => $personne->nom,
            'prenom' => $personne->prenom,
            'nom_complet' => $personne->getAttributes()['nom_complet'] ?? null,
            'surnom' => $personne->surnom,
            'telephone' => $personne->telephone,
            'telephone_secondaire' => $personne->telephone_secondaire,
            'code_phone_pays' => $personne->code_phone_pays,
            'email' => $personne->email,
            'pays' => $personne->pays,
            'ville' => $personne->ville,
            'quartier' => $personne->quartier,
            'adresse' => $personne->adresse,
            'latitude' => $personne->latitude,
            'longitude' => $personne->longitude,
        ];
    }

    /** @return array<string, mixed> */
    public function coordonneesEntreprise(EntrepriseTierce $entreprise): array
    {
        return [
            'id' => $entreprise->id,
            'raison_sociale' => $entreprise->raison_sociale,
            'nom_commercial' => $entreprise->nom_commercial,
            'identifiant_fiscal' => $entreprise->identifiant_fiscal,
            'rccm' => $entreprise->rccm,
            'telephone' => $entreprise->telephone,
            'telephone_secondaire' => $entreprise->telephone_secondaire,
            'code_phone_pays' => $entreprise->code_phone_pays,
            'email' => $entreprise->email,
            'pays' => $entreprise->pays,
            'ville' => $entreprise->ville,
            'quartier' => $entreprise->quartier,
            'adresse' => $entreprise->adresse,
            'latitude' => $entreprise->latitude,
            'longitude' => $entreprise->longitude,
            'is_active' => $entreprise->is_active,
        ];
    }

    /**
     * Options des filtres, limitées aux fonctions lisibles : une fonction non autorisée n'apparaît
     * ni dans les badges ni dans les filtres (CONTACT-013).
     *
     * @param  list<string>  $fonctionsLisibles
     * @return array{fonctions: list<array{value: string, label: string}>, sous_categories: list<array{value: string, label: string}>}
     */
    public function optionsFiltres(array $fonctionsLisibles): array
    {
        $categories = array_values(array_intersect(['client', 'fournisseur', 'prestataire'], $fonctionsLisibles));

        $sousCategories = ContactSousCategorie::where('organization_id', $this->organizationId)
            ->whereIn('categorie', $categories)
            ->actives()
            ->orderBy('categorie')->orderBy('ordre')
            ->get()
            ->map(fn (ContactSousCategorie $s) => [
                'value' => "{$s->categorie->value}:{$s->code}",
                'label' => ContactCategorie::from($s->categorie->value)->label().' — '.$s->libelle,
            ])
            ->values()
            ->all();

        return [
            'fonctions' => array_map(fn (string $f) => ['value' => $f, 'label' => self::LIBELLES_FONCTIONS[$f]], $fonctionsLisibles),
            'sous_categories' => $sousCategories,
        ];
    }

    private function libelleSousCategorie(string $fonction, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match ($fonction) {
            'client', 'prestataire' => $this->libellesSousCategories["{$fonction}:{$code}"] ?? $code,
            'proprietaire' => TypeProprietaire::tryFrom($code)?->label(),
            'employe' => StatutEmploye::tryFrom($code)?->label(),
            default => null,
        };
    }

    /** Lien vers l'écran existant de la fonction ; aucun écran de consultation seule pour fournisseur et prestataire. */
    private function lien(array $f): ?string
    {
        return match ($f['fonction']) {
            'client' => "/backoffice/clients/{$f['id']}",
            'proprietaire' => "/backoffice/proprietaires/{$f['id']}",
            'parrain' => "/backoffice/parrains/{$f['id']}",
            'livreur' => "/livreurs/{$f['id']}",
            'employe' => "/backoffice/employes/{$f['id']}",
            'interlocuteur' => isset($f['entreprise_tierce_id']) ? "/backoffice/contacts/entreprises/{$f['entreprise_tierce_id']}" : null,
            default => null,
        };
    }
}
