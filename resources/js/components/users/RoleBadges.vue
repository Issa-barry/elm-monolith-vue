<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { Shield } from 'lucide-vue-next';

const props = defineProps<{
    roles: string[];
    /**
     * Optionnel : à fournir explicitement seulement quand le périmètre de libellés diffère de
     * celui de l'organisation courante (ex: Accounts/Index.vue, vue plateforme multi-organisations
     * pour un super_admin — cf. AccountController::index()). Sinon, retombe sur
     * `auth.role_labels` (org courante), lui-même déjà scopé correctement pour l'immense
     * majorité des écrans (ex: Users/Index.vue).
     */
    roleLabels?: Record<string, string>;
}>();

const { roleLabel: globalRoleLabel } = usePermissions();

const ROLE_COLORS: Record<string, string> = {
    super_admin:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    admin_entreprise:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    manager:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    commerciale:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    comptable:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

function roleLabel(role: string) {
    return props.roleLabels?.[role] ?? globalRoleLabel(role);
}

function roleColor(role: string) {
    return ROLE_COLORS[role] ?? 'bg-muted text-muted-foreground';
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span
            v-for="role in roles"
            :key="role"
            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="roleColor(role)"
        >
            <Shield class="h-3 w-3" />
            {{ roleLabel(role) }}
        </span>
        <span v-if="roles.length === 0" class="text-xs text-muted-foreground"
            >—</span
        >
    </div>
</template>
