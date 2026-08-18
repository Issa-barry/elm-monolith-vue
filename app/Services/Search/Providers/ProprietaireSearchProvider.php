<?php

namespace App\Services\Search\Providers;

use App\Models\Proprietaire;
use App\Models\User;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use Illuminate\Support\Collection;

class ProprietaireSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'proprietaires';
    }

    public function label(): string
    {
        return 'Propriétaires';
    }

    public function authorize(User $user): bool
    {
        return $user->can('proprietaires.read');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        // nom/prenom/telephone ne sont plus des colonnes de `proprietaires` — l'identité civile
        // est portée par Personne (cf. Proprietaire::$appends et sa docblock). Filtrer via
        // whereHas('personne', ...) puis trier/limiter en collection (accesseurs, pas de colonne
        // à ORDER BY) reproduit le pattern déjà utilisé par ProprietaireController::index().
        $rows = Proprietaire::query()
            ->where('organization_id', $user->organization_id)
            ->whereHas('personne', function ($q) use ($like) {
                $q->where('nom', 'like', $like)
                    ->orWhere('prenom', 'like', $like)
                    ->orWhere('telephone', 'like', $like);
            })
            ->with('personne')
            ->get(['id', 'personne_id'])
            ->sortBy('nom')
            ->take($limit)
            ->values();

        return $rows->map(fn (Proprietaire $p) => new SearchResultItem(
            id: $p->id,
            title: trim($p->prenom.' '.$p->nom),
            subtitle: $p->telephone,
        ));
    }
}
