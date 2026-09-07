<?php

namespace App\Services;

use App\Models\DroitAjustementStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Seul `hasRole('super_admin')` bypass ce service depuis le 2026-09-06 (avant cette date,
 * `isAdmin()` — donc admin_entreprise aussi — bypassait les 5 méthodes, rendant la
 * configuration de `droit_ajustement_stocks` sans effet réel pour ce rôle, même bug que
 * DroitCreationDepenseService avant sa propre correction — cf. sa docblock de classe).
 *
 * Le périmètre configuré (`perimetre`/`sites` sur `droit_ajustement_stocks`) fait seul autorité
 * — jamais recoupé avec le rattachement personnel de l'utilisateur (`$user->sites`), pour rester
 * cohérent avec le service soeur `DroitCreationDepenseService::peutValiderSurSite()`. Décision
 * du 2026-09-07 : une version antérieure de cette correction recoupait `agences_selectionnees`
 * (et même `toutes_agences`) avec les sites personnels de l'utilisateur, ce qui aurait borné
 * admin_entreprise à ses propres sites dès qu'il perdait son bypass isAdmin() — contraire à la
 * règle métier voulue (admin_entreprise garde un périmètre entreprise complet, sans bypass
 * dédié : le même mécanisme de résolution que n'importe quel rôle, avec `toutes_agences` comme
 * périmètre). `toutes_agences` = toutes les agences de l'ORGANISATION, jamais seulement celles de
 * l'acteur ; `agences_selectionnees` = exactement la liste configurée, quel que soit le
 * rattachement personnel de l'acteur à ces sites.
 */
class DroitAjustementStockService
{
    /**
     * L'utilisateur peut-il faire au moins une action d'ajustement quelque part dans son
     * organisation ?
     */
    public function canAjuster(User $user, string $orgId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return DroitAjustementStock::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('peut_augmenter', true)->orWhere('peut_diminuer', true))
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    public function canAugmenter(User $user, string $orgId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return DroitAjustementStock::where('organization_id', $orgId)
            ->where('peut_augmenter', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    public function canDiminuer(User $user, string $orgId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return DroitAjustementStock::where('organization_id', $orgId)
            ->where('peut_diminuer', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    /**
     * L'utilisateur peut-il ajuster dans la direction donnée sur ce site précis ?
     * direction : 'augmenter' | 'diminuer'.
     *
     * Vérifie explicitement que `$siteId` appartient bien à `$orgId` avant de laisser
     * `toutes_agences` répondre vrai sans condition : les appelants actuels (ex.
     * `ProduitController::ajusterStock()`) re-résolvent déjà le site scopé à l'organisation
     * avant d'appeler cette méthode, mais cette méthode ne doit jamais dépendre de la discipline
     * de ses appelants pour son isolation multi-organisation (cf. CLAUDE.md § sécurité).
     */
    public function canAjusterSurSite(User $user, string $orgId, string $siteId, string $direction): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if (! Site::where('id', $siteId)->where('organization_id', $orgId)->exists()) {
            return false;
        }

        $field = $direction === 'augmenter' ? 'peut_augmenter' : 'peut_diminuer';

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where($field, true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        if (! $droit) {
            return false;
        }

        return $droit->isToutesAgences() || in_array($siteId, $droit->sites ?? [], true);
    }

    /**
     * Sites où l'utilisateur est autorisé à ajuster (union augmenter + diminuer) — le périmètre
     * configuré du rôle, jamais recoupé avec les sites personnels de l'utilisateur.
     * null = périmètre "toutes les agences" (super_admin, ou tout rôle configuré ainsi).
     *
     * @return Collection<int, Site>|null
     */
    public function sitesAutorises(User $user, string $orgId): ?Collection
    {
        if ($user->hasRole('super_admin')) {
            return null;
        }

        $droit = DroitAjustementStock::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('peut_augmenter', true)->orWhere('peut_diminuer', true))
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();

        if (! $droit) {
            return collect();
        }

        if ($droit->isToutesAgences()) {
            return null;
        }

        $ids = $droit->sites ?? [];

        return empty($ids)
            ? collect()
            : Site::where('organization_id', $orgId)->whereIn('id', $ids)->orderBy('nom')->get(['id', 'nom', 'code']);
    }
}
