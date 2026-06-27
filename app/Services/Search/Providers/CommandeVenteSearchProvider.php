<?php

namespace App\Services\Search\Providers;

use App\Models\CommandeVente;
use App\Models\User;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;

class CommandeVenteSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'commandes';
    }

    public function label(): string
    {
        return 'Commandes';
    }

    public function authorize(User $user): bool
    {
        return $user->can('ventes.read') || $user->hasRole('client') || $user->hasRole('livreur');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        $commandeQuery = CommandeVente::query()
            ->with('client:id,nom,prenom')
            ->where('organization_id', $user->organization_id);

        if ($user->can('ventes.read')) {
            $commandeQuery = app(SiteScopeService::class)->applyToQuery($commandeQuery, $user);
            $commandeQuery->where(function ($q) use ($like) {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('client', fn ($c) => $c->where('nom', 'like', $like)->orWhere('prenom', 'like', $like))
                    ->orWhereHas('vehicule', fn ($v) => $v->where('nom_vehicule', 'like', $like));
            });
        } elseif ($user->hasRole('client')) {
            $clientId = $user->client?->id;
            if ($clientId === null) {
                return collect();
            }
            $commandeQuery->where('client_id', $clientId)
                ->where('reference', 'like', $like);
        } elseif ($user->hasRole('livreur')) {
            $livreurId = $user->livreur?->id;
            if ($livreurId === null) {
                return collect();
            }
            $commandeQuery->whereHas(
                'vehicule.equipe.livreurs',
                fn ($q) => $q->where('livreurs.id', $livreurId)
            )->where('reference', 'like', $like);
        } else {
            return collect();
        }

        $rows = $commandeQuery
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'reference', 'client_id', 'vehicule_id', 'created_at']);

        return $rows->map(fn (CommandeVente $c) => new SearchResultItem(
            id: $c->id,
            title: $c->reference,
            subtitle: $c->client?->nom_complet,
        ));
    }
}
