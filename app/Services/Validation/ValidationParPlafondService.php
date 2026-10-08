<?php

namespace App\Services\Validation;

use App\Models\RegleValidationRole;
use App\Models\User;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Validation par plafond porté par le rôle, générique par domaine métier (ADR 0021).
 *
 * Une règle porte, pour un rôle et un domaine, un périmètre d'agences (pour les achats : « Peut
 * acheter pour », qui gouverne aussi la création et la lecture) et un plafond de validation.
 *
 * Valider = permission du domaine (vérifiée par l'appelant) ET une règle d'un des rôles de
 * l'utilisateur qui couvre l'agence ET un montant inférieur ou égal au plafond (égalité autorisée).
 * - Règle sans plafond (et pas « sans limite ») : périmètre seul, ne valide rien.
 * - Plusieurs rôles : la règle la plus favorable parmi celles qui couvrent l'agence s'applique.
 * - Pas de règle = plafond 0 : le rôle ne valide rien. « Sans limite » est un choix explicite.
 * - AUCUNE exception, super administrateur compris : il lui faut aussi une règle (décision du
 *   07/10/2026). Les dépenses gardent leur propre mécanisme et leur exception DEPVAL-001
 *   (DroitCreationDepenseService), non modifiés ici.
 */
class ValidationParPlafondService
{
    public function peutValider(User $user, string $domaine, ?string $siteId, float $montant): bool
    {
        return $this->motifRefus($user, $domaine, $siteId, $montant) === null;
    }

    /**
     * Message explicite si l'utilisateur ne peut pas valider ce montant pour cette agence, null
     * sinon. Ne vérifie PAS la permission du domaine.
     */
    public function motifRefus(User $user, string $domaine, ?string $siteId, float $montant): ?string
    {
        $regles = $this->reglesDe($user, $domaine);
        if ($regles->isEmpty()) {
            return "Aucun plafond de validation n'est configuré pour votre rôle.";
        }

        $regle = $this->meilleureRegle($regles->filter(fn (RegleValidationRole $r) => $this->regleCouvreSite($r, $user, $siteId)));
        if ($regle === null) {
            return 'Votre plafond de validation ne couvre pas cette agence.';
        }

        if (! $regle->plafond_illimite && $regle->plafond === null) {
            return "Votre rôle n'a pas de plafond de validation.";
        }

        if (! $regle->plafond_illimite && $montant > (float) $regle->plafond) {
            return 'Montant supérieur à votre plafond de validation ('.self::formaterMontant((float) $regle->plafond).').';
        }

        return null;
    }

    /**
     * Agences couvertes par les règles des rôles de l'utilisateur (« Peut acheter pour » pour les
     * achats) : null = toutes les agences de l'organisation, [] = aucune. Aucune exception de rôle.
     *
     * @return list<string>|null
     */
    public function sitesCouverts(User $user, string $domaine): ?array
    {
        $sites = [];

        foreach ($this->reglesDe($user, $domaine) as $regle) {
            $sites = match ($regle->perimetre) {
                'toutes_agences' => null,
                'son_agence' => [...$sites, ...$user->sites()->pluck('sites.id')->all()],
                default => [...$sites, ...array_values($regle->sites ?? [])],
            };
            if ($sites === null) {
                return null;
            }
        }

        return array_values(array_unique($sites));
    }

    public function couvreSite(User $user, string $domaine, ?string $siteId): bool
    {
        return $siteId !== null
            && $this->reglesDe($user, $domaine)->contains(fn (RegleValidationRole $r) => $this->regleCouvreSite($r, $user, $siteId));
    }

    /**
     * Règle qui autorise la validation (la plus favorable couvrant l'agence), ou null si
     * l'utilisateur ne peut pas valider ce montant. Sert au snapshot de la validation.
     */
    public function regleAppliquee(User $user, string $domaine, ?string $siteId, float $montant): ?RegleValidationRole
    {
        if ($this->motifRefus($user, $domaine, $siteId, $montant) !== null) {
            return null;
        }

        return $this->meilleureRegle(
            $this->reglesDe($user, $domaine)->filter(fn (RegleValidationRole $r) => $this->regleCouvreSite($r, $user, $siteId))
        );
    }

    /**
     * Périmètres de validation de l'utilisateur, pour filtrer une liste « à valider par moi » en
     * SQL : une entrée par règle, `sites` null = toutes les agences, `plafond` null = sans limite.
     *
     * @return list<array{sites: list<string>|null, plafond: float|null}>
     */
    public function perimetresDeValidation(User $user, string $domaine): array
    {
        $sitesUtilisateur = null;

        return $this->reglesDe($user, $domaine)
            ->filter(fn (RegleValidationRole $r) => $r->plafond_illimite || $r->plafond !== null)
            ->map(function (RegleValidationRole $r) use ($user, &$sitesUtilisateur) {
                $sites = match ($r->perimetre) {
                    'toutes_agences' => null,
                    'son_agence' => $sitesUtilisateur ??= $user->sites()->pluck('sites.id')->all(),
                    default => array_values($r->sites ?? []),
                };

                return [
                    'sites' => $sites,
                    'plafond' => $r->plafond_illimite ? null : (float) $r->plafond,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Rôles de l'organisation qui peuvent valider ce montant pour cette agence (bandeau « validable
     * par »). Un rôle doit avoir la permission ET une règle suffisante. « Son agence » est
     * considéré comme couvrant : il dépend de l'agence de chaque utilisateur.
     *
     * @return list<array{role: string, label: string, plafond: float|null}>
     */
    public function rolesPouvantValider(string $orgId, string $domaine, string $permission, ?string $siteId, float $montant): array
    {
        $regles = RegleValidationRole::where('organization_id', $orgId)
            ->where('domaine', $domaine)
            ->get()
            ->keyBy('role_name');

        return RoleVisibility::query($orgId)
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->filter(function (Role $role) use ($regles, $permission, $siteId, $montant) {
                $regle = $regles->get($role->name);
                if ($regle === null || ! $role->permissions->contains('name', $permission)) {
                    return false;
                }
                $couvre = match ($regle->perimetre) {
                    'agences_selectionnees' => $siteId !== null && in_array($siteId, $regle->sites ?? [], true),
                    default => true,
                };

                return $couvre && ($regle->plafond_illimite || ($regle->plafond !== null && $montant <= (float) $regle->plafond));
            })
            ->map(fn (Role $role) => [
                'role' => $role->name,
                'label' => $role->label ?: $role->name,
                'plafond' => $regles->get($role->name)->plafond_illimite ? null : (float) $regles->get($role->name)->plafond,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, RegleValidationRole> */
    private function reglesDe(User $user, string $domaine): Collection
    {
        return RegleValidationRole::where('organization_id', $user->organization_id)
            ->where('domaine', $domaine)
            ->whereIn('role_name', $user->roles->pluck('name')->all())
            ->get();
    }

    private function regleCouvreSite(RegleValidationRole $regle, User $user, ?string $siteId): bool
    {
        return match ($regle->perimetre) {
            'toutes_agences' => true,
            'son_agence' => $siteId !== null && $user->sites()->where('sites.id', $siteId)->exists(),
            default => $siteId !== null && in_array($siteId, $regle->sites ?? [], true),
        };
    }

    /** @param  Collection<int, RegleValidationRole>  $regles */
    private function meilleureRegle(Collection $regles): ?RegleValidationRole
    {
        return $regles
            ->sortByDesc(fn (RegleValidationRole $r) => $r->plafond_illimite ? INF : (float) ($r->plafond ?? 0))
            ->first();
    }

    public static function formaterMontant(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }
}
