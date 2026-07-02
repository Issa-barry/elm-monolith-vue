<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\Search\Providers\ClientSearchProvider;
use App\Services\Search\Providers\CommandeVenteSearchProvider;
use App\Services\Search\Providers\FactureVenteSearchProvider;
use App\Services\Search\Providers\ProprietaireSearchProvider;
use App\Services\Search\Providers\VehiculeSearchProvider;

class GlobalSearchService
{
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
        ];
    }

    /**
     * @param  list<string>|null  $onlyCategories  Si fourni, restreint aux clés données (et toujours
     *                                             filtré par authorize() — ne contourne jamais les permissions).
     * @return array<string, array{label: string, total: int, items: array}>
     */
    public function search(string $query, User $user, int $limit = 5, ?array $onlyCategories = null): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            if ($onlyCategories !== null && ! in_array($provider->key(), $onlyCategories, true)) {
                continue;
            }

            if (! $provider->authorize($user)) {
                continue;
            }

            $items = $provider->search($query, $user, $limit);

            $results[$provider->key()] = [
                'label' => $provider->label(),
                'total' => $items->count(),
                'items' => $items->map(fn (SearchResultItem $item) => $item->toArray())->values()->all(),
            ];
        }

        return $results;
    }
}
