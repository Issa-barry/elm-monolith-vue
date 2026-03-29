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

    /** Vérifie une permission précise, ex: can('clients.read') */
    function can(permission: PermissionKey): boolean {
        return permissions.value[permission] === true;
    }

    /** Vérifie si l'utilisateur a un rôle donné */
    function hasRole(role: AppRole): boolean {
        return (roles.value as string[]).includes(role);
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

    return { can, hasRole, canAny, canAll, canOnResource, permissions, roles };
}
