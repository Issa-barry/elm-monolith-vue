<?php

namespace App\Services\Search\Providers;

use App\Models\FactureVente;
use App\Models\User;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;

class FactureVenteSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'factures';
    }

    public function label(): string
    {
        return 'Factures';
    }

    public function authorize(User $user): bool
    {
        return $user->can('factures.read') || $user->hasRole('client');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        $factureQuery = FactureVente::query()
            ->with('commande.client:id,nom,prenom')
            ->where('organization_id', $user->organization_id);

        if ($user->can('factures.read')) {
            $factureQuery = app(SiteScopeService::class)->applyToQuery($factureQuery, $user);
            $factureQuery->where(function ($q) use ($like) {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('commande.client', fn ($c) => $c->where('nom', 'like', $like)->orWhere('prenom', 'like', $like));
            });
        } elseif ($user->hasRole('client')) {
            $clientId = $user->client?->id;
            if ($clientId === null) {
                return collect();
            }
            $factureQuery->whereHas('commande', fn ($q) => $q->where('client_id', $clientId))
                ->where('reference', 'like', $like);
        } else {
            return collect();
        }

        $rows = $factureQuery
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'reference', 'commande_vente_id', 'created_at']);

        return $rows->map(fn (FactureVente $f) => new SearchResultItem(
            id: $f->id,
            title: $f->reference,
            subtitle: $f->commande?->client?->nom_complet,
        ));
    }
}
