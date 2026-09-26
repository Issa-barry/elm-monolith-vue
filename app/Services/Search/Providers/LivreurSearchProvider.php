<?php

namespace App\Services\Search\Providers;

use App\Models\Livreur;
use App\Models\User;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use Illuminate\Support\Collection;

class LivreurSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'livreurs';
    }

    public function label(): string
    {
        return 'Livreurs';
    }

    public function authorize(User $user): bool
    {
        return $user->can('livreurs.read');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        // nom_complet est une vraie colonne de `livreurs` (désignation opérationnelle,
        // jamais l'identité civile — cf. Livreur::$fillable) ; telephone est un accesseur
        // proxy vers Personne, d'où le whereHas (même pattern que ProprietaireSearchProvider).
        $rows = Livreur::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($like) {
                $q->where('nom_complet', 'like', $like)
                    ->orWhereHas('personne', fn ($p) => $p->where('telephone', 'like', $like));
            })
            ->with('personne')
            ->orderByRaw('is_active DESC, nom_complet')
            ->limit($limit)
            ->get(['id', 'personne_id', 'nom_complet', 'is_active']);

        return $rows->map(fn (Livreur $l) => new SearchResultItem(
            id: $l->id,
            title: $l->nom_complet ?: 'Livreur',
            subtitle: $l->telephone,
        ));
    }
}
