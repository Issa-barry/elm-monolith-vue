import type {
    AppPageProps,
    AppRole,
    CrudAction,
    PermissionKey,
    Resource,
} from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function usePermissions() {
    const page = usePage<AppPageProps>();

    const permissions = computed(() => page.props.auth?.permissions ?? {});
    const roles = computed(() => page.props.auth?.roles ?? []);
    const roleLabels = computed(() => page.props.auth?.role_labels ?? {});

    /**
     * Libellé humain d'un rôle (role.label côté backend) — remplace les dictionnaires
     * ROLE_LABELS locaux qui existaient dans chaque page consommant un rôle (cf.
     * Auth.role_labels). Fallback sur le nom technique si absent (rôle externe non exposé par
     * HandleInertiaRequests::roleLabels(), ou libellé jamais renseigné).
     */
    function roleLabel(role: AppRole): string {
        return roleLabels.value[role] ?? role;
    }

    /** Vérifie une permission précise, ex: can('clients.read') */
    function can(permission: PermissionKey): boolean {
        return permissions.value[permission] === true;
    }

    /** Vérifie si l'utilisateur a un rôle donné */
    function hasRole(role: AppRole): boolean {
        return (roles.value as string[]).includes(role);
    }

    /** Vérifie si l'utilisateur a au moins un des rôles donnés */
    function hasAnyRole(candidateRoles: AppRole[]): boolean {
        return candidateRoles.some(hasRole);
    }

    /** Vérifie si au moins une des permissions est accordée */
    function canAny(...perms: PermissionKey[]): boolean {
        return perms.some(can);
    }

    /** Vérifie si toutes les permissions sont accordées */
    function canAll(...perms: PermissionKey[]): boolean {
        return perms.every(can);
    }

    /** Raccourci : can('clients.read') ↔ canOnResource('clients', 'read') */
    function canOnResource(resource: Resource, action: CrudAction): boolean {
        return can(`${resource}.${action}`);
    }

    return {
        can,
        hasRole,
        hasAnyRole,
        canAny,
        canAll,
        canOnResource,
        permissions,
        roles,
        roleLabels,
        roleLabel,
    };
}
