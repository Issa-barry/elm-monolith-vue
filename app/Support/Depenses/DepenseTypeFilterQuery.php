<?php

namespace App\Support\Depenses;

use App\Models\DepenseType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Ré-applique côté serveur les mêmes filtres que la liste (concerné, statut) — extrait de
 * DepenseTypeController::filteredTypes(), partagé entre les exports Excel et PDF pour que
 * l'export reflète toujours l'ensemble des lignes filtrées, pas seulement la page actuellement
 * affichée côté client (celle-ci n'est jamais paginée côté serveur pour ce module).
 */
final class DepenseTypeFilterQuery
{
    /** @return Collection<int, DepenseType> */
    public static function pour(Request $request): Collection
    {
        $orgId = auth()->user()->organization_id;
        $categorie = (string) $request->input('categorie', '');
        $statut = (string) $request->input('statut', '');

        return DepenseType::where('organization_id', $orgId)
            ->when($categorie !== '', fn ($q) => $q->where('categorie', $categorie))
            ->when($statut === 'actif', fn ($q) => $q->where('is_active', true))
            ->when($statut === 'inactif', fn ($q) => $q->where('is_active', false))
            ->ordered()
            ->get();
    }
}
