import { usePermissions } from '@/composables/usePermissions';
import { logout } from '@/routes';
import { dashboard as clientDashboard } from '@/routes/client';
import { edit as editParametres } from '@/routes/parametres';
import { edit as editProfile } from '@/routes/profile';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Actions du menu utilisateur, partagées par le profil de la barre supérieure
 * (AppTopbarProfile, mise en page à menu latéral) et UserMenuContent (mise en page AppHeader).
 */
export function useUserMenuActions() {
    const { can, hasAnyRole } = usePermissions();

    const canReadParametres = computed(() => can('parametres.read'));

    const settingsHref = computed(() =>
        canReadParametres.value ? editParametres().url : editProfile().url,
    );

    // Un compte staff peut aussi porter un rôle client/proprietaire/livreur (ex: un
    // admin qui possède lui-même un véhicule) — cf. EnsureIsStaffAccount côté backend,
    // qui autorise ce cumul plutôt que de forcer un second compte.
    const canAccessClientSpace = computed(() =>
        hasAnyRole(['client', 'proprietaire', 'livreur']),
    );

    const visit = (url: string) => {
        router.flushAll();
        router.visit(url);
    };

    const openProfile = () => visit(editProfile().url);
    const openSettings = () => visit(settingsHref.value);
    const openClientSpace = () => visit(clientDashboard().url);

    const handleLogout = () => {
        router.flushAll();
        router.post(logout().url);
    };

    return {
        canReadParametres,
        canAccessClientSpace,
        openProfile,
        openSettings,
        openClientSpace,
        handleLogout,
    };
}
