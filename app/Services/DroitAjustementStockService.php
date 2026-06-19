<?php

namespace App\Services;

use App\Models\DroitAjustementStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

class DroitAjustementStockService
{
    /**
     * L'utilisateur peut-il faire au moins une action d'ajustement
     * sur au moins un de ses sites personnels ?
     */
    public function canAjuster(User $user, string $orgId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('peut_augmenter', true)->orWhere('peut_diminuer', true))
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        $userSiteIds = $user->sites->where('organization_id', $orgId)->pluck('id')->all();

        return $droit && ! empty($userSiteIds) && (
            $droit->isToutesAgences() || ! empty(array_intersect($userSiteIds, $droit->sites ?? []))
        );
    }

    public function canAugmenter(User $user, string $orgId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where('peut_augmenter', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        $userSiteIds = $user->sites->where('organization_id', $orgId)->pluck('id')->all();

        return $droit && ! empty($userSiteIds) && (
            $droit->isToutesAgences() || ! empty(array_intersect($userSiteIds, $droit->sites ?? []))
        );
    }

    public function canDiminuer(User $user, string $orgId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where('peut_diminuer', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        $userSiteIds = $user->sites->where('organization_id', $orgId)->pluck('id')->all();

        return $droit && ! empty($userSiteIds) && (
            $droit->isToutesAgences() || ! empty(array_intersect($userSiteIds, $droit->sites ?? []))
        );
    }

    /**
     * L'utilisateur peut-il ajuster dans la direction donnée sur ce site précis ?
     * Vérifie : rôle autorisé + site dans le périmètre + user affecté au site.
     * direction : 'augmenter' | 'diminuer'
     */
    public function canAjusterSurSite(User $user, string $orgId, string $siteId, string $direction): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $field = $direction === 'augmenter' ? 'peut_augmenter' : 'peut_diminuer';

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where($field, true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        return $droit
            && $user->sites->contains('id', $siteId)
            && ($droit->isToutesAgences() || in_array($siteId, $droit->sites ?? [], true));
    }

    /**
     * Sites où l'utilisateur est autorisé à ajuster (union augmenter + diminuer),
     * intersectés avec ses propres sites.
     * null = admin (toutes les agences de l'org).
     *
     * @return Collection<int, Site>|null
     */
    public function sitesAutorises(User $user, string $orgId): ?Collection
    {
        if ($user->isAdmin()) {
            return null;
        }

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('peut_augmenter', true)->orWhere('peut_diminuer', true))
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        $userSiteIds = $user->sites->where('organization_id', $orgId)->pluck('id')->all();

        if (! $droit || empty($userSiteIds)) {
            return collect();
        }

        $ids = $droit->isToutesAgences()
            ? $userSiteIds
            : array_values(array_intersect($userSiteIds, $droit->sites ?? []));

        return empty($ids)
            ? collect()
            : Site::where('organization_id', $orgId)->whereIn('id', $ids)->orderBy('nom')->get(['id', 'nom', 'code']);
    }
}
