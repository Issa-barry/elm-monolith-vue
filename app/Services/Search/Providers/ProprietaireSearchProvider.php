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

        $rows = Proprietaire::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($like) {
                $q->where('nom', 'like', $like)
                    ->orWhere('prenom', 'like', $like)
                    ->orWhere('telephone', 'like', $like);
            })
            ->orderBy('nom')
            ->limit($limit)
            ->get(['id', 'nom', 'prenom', 'telephone']);

        return $rows->map(fn (Proprietaire $p) => new SearchResultItem(
            id: $p->id,
            title: trim($p->prenom.' '.$p->nom),
            subtitle: $p->telephone,
        ));
    }
}
