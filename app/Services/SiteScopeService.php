<?php

namespace App\Services;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Périmètre d'agences en CONSULTATION (listes, détails, recherches, exports, statistiques) : toute
 * l'organisation pour qui voit toutes les agences (User::voitToutesLesAgences() — administrateur
 * ou permission « vision 360° », ADR 0025), ses agences `user_sites` sinon.
 *
 * Ne sert jamais à autoriser une écriture : pour cela, assignedSiteIds() (rattachement réel).
 */
class SiteScopeService
{
    /**
     * Retourne les IDs de sites consultables par l'utilisateur.
     * Vision de toutes les agences → collection vide (= pas de restriction).
     * Sinon → sites affectés via user_sites.
     */
    public function accessibleSiteIds(User $user): Collection
    {
        if ($user->voitToutesLesAgences()) {
            return collect();
        }

        return $user->sites()->pluck('sites.id');
    }

    /**
     * Périmètre d'ÉCRITURE : les agences auxquelles l'utilisateur est réellement rattaché. La vision
     * 360° ne l'élargit pas. Administrateur → collection vide (= pas de restriction).
     */
    public function assignedSiteIds(User $user): Collection
    {
        if ($user->isAdmin()) {
            return collect();
        }

        return $user->sites()->pluck('sites.id');
    }

    /**
     * Applique le périmètre de consultation sur une query Eloquent.
     * La colonne de site peut être personnalisée (ex: 'site_id').
     */
    public function applyToQuery(Builder $query, User $user, string $column = 'site_id'): Builder
    {
        if ($user->voitToutesLesAgences()) {
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
     * Périmètre limité à ses agences : ignore le param URL, retourne ''.
     * Vision de toutes les agences : retourne la valeur du param.
     */
    public function resolveFiltreSite(User $user, string $param = ''): string
    {
        if (! $user->voitToutesLesAgences()) {
            return '';
        }

        return trim($param);
    }

    /**
     * Retourne les props Inertia pour le filtre site :
     *   - is_admin : bool (filtre Agence libre — toutes les agences consultables)
     *   - sites    : [{ value: id, label: nom }]
     *   - filtre_site : valeur actuelle (vide si le périmètre est limité à ses agences)
     */
    public function inertiaProps(User $user, string $orgId, string $filtreParam = ''): array
    {
        $toutesAgences = $user->voitToutesLesAgences();

        if ($toutesAgences) {
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
            'is_admin' => $toutesAgences,
            'sites' => $sites,
            'filtre_site' => $toutesAgences ? trim($filtreParam) : '',
        ];
    }
}
