<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use App\Services\Search\Providers\ClientSearchProvider;
use App\Services\Search\Providers\CommandeVenteSearchProvider;
use App\Services\Search\Providers\ContactSearchProvider;
use App\Services\Search\Providers\FactureVenteSearchProvider;
use App\Services\Search\Providers\LivreurSearchProvider;
use App\Services\Search\Providers\ProprietaireSearchProvider;
use App\Services\Search\Providers\VehiculeSearchProvider;

class GlobalSearchService
{
    /**
     * Lot 8 (ADR 0060) : groupes historiques remplacés par le groupe « Contacts » au back-office,
     * avec la fonction Contacts qui doit être lisible pour que le remplacement ne perde rien.
     */
    public const GROUPES_CONTACTS = ['clients' => 'client', 'proprietaires' => 'proprietaire', 'livreurs' => 'livreur'];

    /** Plafond du groupe « Contacts » regroupé (même maximum que la requête). */
    private const LIMITE_MAX = 20;

    /** @var list<SearchProvider> */
    private array $providers;

    public function __construct()
    {
        // Ordre d'affichage des groupes dans la réponse.
        $this->providers = [
            new ClientSearchProvider,
            new CommandeVenteSearchProvider,
            new FactureVenteSearchProvider,
            new VehiculeSearchProvider,
            new ProprietaireSearchProvider,
            new LivreurSearchProvider,
            // Référentiel Contacts (ADR 0060, lot 2) : en dernier, les groupes historiques restent en tête.
            new ContactSearchProvider,
        ];
    }

    /**
     * @param  list<string>|null  $onlyCategories  Si fourni, restreint aux clés données (et toujours
     *                                             filtré par authorize() — ne contourne jamais les permissions).
     * @param  bool  $regrouperContacts  Back-office (lot 8) : un seul groupe « Contacts » en tête, à la
     *                                   place des groupes Clients, Propriétaires et Livreurs. L'API
     *                                   v1/search/global garde ses groupes (contrat mobile).
     * @return array<string, array{label: string, total: int, items: array}>
     */
    public function search(string $query, User $user, int $limit = 5, ?array $onlyCategories = null, bool $regrouperContacts = false): array
    {
        $absorbes = $regrouperContacts && $onlyCategories === null ? $this->groupesAbsorbes($user) : null;
        $providers = $this->providers;
        if ($absorbes !== null) {
            // « Contacts » prend la place du groupe Clients, en tête de la liste.
            $contacts = array_values(array_filter($providers, fn (SearchProvider $p) => $p instanceof ContactSearchProvider));
            $autres = array_filter($providers, fn (SearchProvider $p) => ! $p instanceof ContactSearchProvider && ! in_array($p->key(), $absorbes, true));
            $providers = [...$contacts, ...array_values($autres)];
        }

        $results = [];

        foreach ($providers as $provider) {
            if ($onlyCategories !== null && ! in_array($provider->key(), $onlyCategories, true)) {
                continue;
            }

            if (! $provider->authorize($user)) {
                continue;
            }

            // Le groupe regroupé affiche autant de résultats que les groupes qu'il remplace.
            $limite = $absorbes !== null && $provider instanceof ContactSearchProvider
                ? min(self::LIMITE_MAX, $limit * (1 + count($absorbes)))
                : $limit;

            $items = $provider->search($query, $user, $limite);

            $results[$provider->key()] = [
                'label' => $provider->label(),
                'total' => $items->count(),
                'items' => $items->map(fn (SearchResultItem $item) => $item->toArray())->values()->all(),
            ];
        }

        return $results;
    }

    /**
     * Groupes historiques que « Contacts » remplace pour cet utilisateur : ceux qu'il peut voir et
     * dont la fonction est lisible dans Contacts. Sans `contacts.read`, aucun regroupement.
     *
     * @return list<string>|null
     */
    private function groupesAbsorbes(User $user): ?array
    {
        if (! (new ContactSearchProvider)->authorize($user)) {
            return null;
        }

        $visibilite = app(VisibiliteContacts::class);
        $absorbes = [];
        foreach ($this->providers as $provider) {
            $fonction = self::GROUPES_CONTACTS[$provider->key()] ?? null;
            if ($fonction !== null && $provider->authorize($user) && $visibilite->peutLire($user, $fonction)) {
                $absorbes[] = $provider->key();
            }
        }

        return $absorbes;
    }
}
