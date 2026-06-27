<?php

namespace App\Services\Search\Providers;

use App\Models\Client;
use App\Models\User;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use Illuminate\Support\Collection;

class ClientSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'clients';
    }

    public function label(): string
    {
        return 'Clients';
    }

    public function authorize(User $user): bool
    {
        return $user->can('clients.read');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        $rows = Client::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($like) {
                $q->where('nom', 'like', $like)
                    ->orWhere('prenom', 'like', $like)
                    ->orWhere('telephone', 'like', $like);
            })
            ->orderBy('nom')
            ->limit($limit)
            ->get(['id', 'nom', 'prenom', 'telephone']);

        return $rows->map(fn (Client $c) => new SearchResultItem(
            id: $c->id,
            title: $c->nom_complet,
            subtitle: $c->telephone,
        ));
    }
}
