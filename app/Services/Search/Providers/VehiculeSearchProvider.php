<?php

namespace App\Services\Search\Providers;

use App\Models\User;
use App\Models\Vehicule;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;

class VehiculeSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

    public function key(): string
    {
        return 'vehicules';
    }

    public function label(): string
    {
        return 'Véhicules';
    }

    public function authorize(User $user): bool
    {
        return $user->can('vehicules.read') || $user->hasRole('proprietaire');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        $vehiculeQuery = Vehicule::query()
            ->where('organization_id', $user->organization_id);

        if ($user->can('vehicules.read')) {
            $vehiculeQuery->where(function ($q) use ($like) {
                $q->where('nom_vehicule', 'like', $like)
                    ->orWhere('immatriculation', 'like', $like);
            });
            $vehiculeQuery = app(SiteScopeService::class)->applyToQuery($vehiculeQuery, $user);
        } elseif ($user->hasRole('proprietaire')) {
            $proprietaireId = $user->proprietaire?->id;
            if ($proprietaireId === null) {
                return collect();
            }
            $vehiculeQuery
                ->where('proprietaire_id', $proprietaireId)
                ->where(function ($q) use ($like) {
                    $q->where('nom_vehicule', 'like', $like)
                        ->orWhere('immatriculation', 'like', $like);
                });
        }

        $rows = $vehiculeQuery
            ->orderBy('nom_vehicule')
            ->limit($limit)
            ->get(['id', 'nom_vehicule', 'immatriculation']);

        return $rows->map(fn (Vehicule $v) => new SearchResultItem(
            id: $v->id,
            title: $v->nom_vehicule,
            subtitle: $v->immatriculation,
        ));
    }
}
