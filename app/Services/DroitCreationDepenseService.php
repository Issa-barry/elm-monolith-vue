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
     * Seul Super Admin est bypassé — admin_entreprise doit avoir une ligne DroitCreationDepense
     * comme n'importe quel rôle depuis le 2026-09-06 (cf. docblock de classe et
     * peutValiderMontant()/droitValidationPour(), déjà sur ce modèle depuis le 04/09/2026).
     */
    public function peutCreer(User $user, string $orgId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return DroitCreationDepense::where('organization_id', $orgId)
            ->where('is_actif', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    /**
     * L'utilisateur peut-il créer une dépense sur ce site précis ?
     * Seul Super Admin est bypassé (cf. peutCreer()).
     */
    public function peutCreerSurSite(User $user, string $orgId, string $siteId): bool
    {
        if ($user->hasRole('super_admin')) {
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
     * Retourne la ligne DroitCreationDepense de validation pour l'utilisateur,
     * ou null si Super Admin (seul bypass total restant) ou aucun droit.
     *
     * Admin Entreprise n'est bypassé nulle part dans ce service depuis le 2026-09-06 (avant
     * cette date, seul le plafond de validation lui était déjà appliqué — décision produit du
     * 04/09/2026, cf. docs/depenses-validation.md, DEPVAL-001 — mais peutCreer/peutCreerSurSite/
     * peutValider/peutValiderSurSite/sitesAutorises le bypassaient encore, rendant la
     * configuration de DroitCreationDepense sans effet réel pour ce rôle). Il doit désormais
     * avoir une ligne DroitCreationDepense comme n'importe quel rôle, sur toute la surface du
     * service — seul Super Admin reste réellement illimité.
     */
    public function droitValidationPour(User $user, string $orgId): ?DroitCreationDepense
    {
        if ($user->hasRole('super_admin')) {
            return null;
        }

        return DroitCreationDepense::where('organization_id', $orgId)
            ->where('peut_valider', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->first();
    }

    /**
     * L'utilisateur peut-il valider la dépense d'un site donné ?
     * Utilise un droit pré-chargé pour éviter les requêtes N+1. Seul Super Admin est bypassé
     * (cf. peutCreer()) — admin_entreprise doit désormais avoir un périmètre d'agences
     * configuré comme n'importe quel rôle.
     */
    public function peutValiderSurSite(User $user, ?DroitCreationDepense $droit, ?string $siteId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if (! $droit) {
            return false;
        }
        if ($droit->isToutesAgences()) {
            return true;
        }
        if ($droit->perimetre === 'son_agence') {
            return $user->sites()->where('sites.id', $siteId)->exists();
        }

        return in_array($siteId, $droit->sites ?? [], true);
    }

    /**
     * L'utilisateur peut-il valider une dépense de ce montant ?
     * Seul Super Admin est illimité — Admin Entreprise doit avoir un droit
     * avec plafond comme tout autre rôle (cf. droitValidationPour()). Sans
     * droit, refusé. Un plafond non configuré (NULL) vaut 0 — deny-by-default,
     * jamais interprété comme "illimité".
     */
    public function peutValiderMontant(User $user, ?DroitCreationDepense $droit, float $montant): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if (! $droit) {
            return false;
        }

        return $montant <= (float) ($droit->plafond_validation ?? 0);
    }

    /**
     * L'utilisateur peut-il valider des dépenses ?
     * Seul Super Admin est bypassé (cf. peutCreer()).
     */
    public function peutValider(User $user, string $orgId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return DroitCreationDepense::where('organization_id', $orgId)
            ->where('peut_valider', true)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->exists();
    }

    /**
     * Retourne les sites autorisés pour la création de dépenses.
     * null = toutes les agences. Seul Super Admin est bypassé (cf. peutCreer()).
     *
     * @return Collection<int, Site>|null
     */
    public function sitesAutorises(User $user, string $orgId): ?Collection
    {
        if ($user->hasRole('super_admin')) {
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
