<script setup lang="ts">
import { Shield } from 'lucide-vue-next';

const props = defineProps<{
    roles: string[];
    roleLabels?: Record<string, string>;
}>();

/**
 * Labels/couleurs par défaut pour les rôles historiques — utilisés en secours
 * quand `roleLabels` (fourni par le backend, cf. UserController::indexProps)
 * ne connaît pas encore le rôle (rôle système avant migration des labels, ou
 * rôle externe comme `client`/`proprietaire`/`livreur`).
 */
const FALLBACK_LABELS: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur',
    manager: 'Manager',
    commerciale: 'Commercial(e)',
    comptable: 'Comptable',
    client: 'Client',
    proprietaire: 'Propriétaire',
    livreur: 'Livreur',
};

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
    return props.roleLabels?.[role] ?? FALLBACK_LABELS[role] ?? role;
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
