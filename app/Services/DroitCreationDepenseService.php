<?php

namespace App\Services;

use App\Models\DroitCreationDepense;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

class DroitCreationDepenseService
{
    /**
     * L'utilisateur peut-il créer des dépenses ?
     * Admin = toujours autorisé.
     */
    public function peutCreer(User $user, string $orgId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return DroitCreationDepense::where('organization_id', $orgId)
            ->where('is_actif', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    /**
     * L'utilisateur peut-il créer une dépense sur ce site précis ?
     */
    public function peutCreerSurSite(User $user, string $orgId, string $siteId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $droit = DroitCreationDepense::where('organization_id', $orgId)
            ->where('is_actif', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        if (! $droit) {
            return false;
        }

        if ($droit->isToutesAgences()) {
            return true;
        }

        return in_array($siteId, $droit->sites ?? [], true);
    }

    /**
     * L'utilisateur peut-il valider des dépenses ?
     * Admin = toujours autorisé.
     */
    public function peutValider(User $user, string $orgId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return DroitCreationDepense::where('organization_id', $orgId)
            ->where('peut_valider', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    /**
     * Retourne les sites autorisés pour la création de dépenses.
     * null = toutes les agences.
     *
     * @return Collection<int, Site>|null
     */
    public function sitesAutorises(User $user, string $orgId): ?Collection
    {
        if ($user->isAdmin()) {
            return null;
        }

        $droit = DroitCreationDepense::where('organization_id', $orgId)
            ->where('is_actif', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        if (! $droit) {
            return collect();
        }

        if ($droit->isToutesAgences()) {
            return null;
        }

        $siteIds = $droit->sites ?? [];

        return Site::where('organization_id', $orgId)
            ->whereIn('id', $siteIds)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code']);
    }
}
