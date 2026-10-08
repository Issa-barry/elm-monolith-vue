<?php

namespace App\Services;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SiteScopeService
{
    /**
     * Retourne les IDs de sites accessibles pour l'utilisateur.
     * Admin → collection vide (= pas de restriction).
     * Non-admin → sites affectés via user_sites.
     */
    public function accessibleSiteIds(User $user): Collection
    {
        if ($user->isAdmin()) {
            return collect();
        }

        return $user->sites()->pluck('sites.id');
    }

    /**
     * Applique le scoping de site sur une query Eloquent.
     * La colonne de site peut être personnalisée (ex: 'site_id').
     */
    public function applyToQuery(Builder $query, User $user, string $column = 'site_id'): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user);

        if ($siteIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $siteIds);
    }

    /**
     * Résout le filtre site depuis la requête.
     * Pour un non-admin : ignore le param URL, retourne ''.
     * Pour un admin : retourne la valeur du param.
     */
    public function resolveFiltreSite(User $user, string $param = ''): string
    {
        if (! $user->isAdmin()) {
            return '';
        }

        return trim($param);
    }

    /**
     * Retourne les props Inertia pour le filtre site :
     *   - is_admin : bool
     *   - sites    : [{ value: id, label: nom }]
     *   - filtre_site : valeur actuelle (vide pour non-admin)
     */
    public function inertiaProps(User $user, string $orgId, string $filtreParam = ''): array
    {
        $isAdmin = $user->isAdmin();

        if ($isAdmin) {
            $sites = Site::where('organization_id', $orgId)
                ->orderBy('nom')
                ->get(['id', 'nom'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])
                ->values();
        } else {
            $sites = $user->sites()
                ->orderBy('sites.nom')
                ->get(['sites.id', 'sites.nom'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])
                ->values();
        }

        return [
            'is_admin' => $isAdmin,
            'sites' => $sites,
            'filtre_site' => $isAdmin ? trim($filtreParam) : '',
        ];
    }
}
