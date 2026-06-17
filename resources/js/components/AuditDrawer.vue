<script setup lang="ts">
import PvSidebar from 'primevue/sidebar';
import { ref, watch } from 'vue';

interface LogRow {
    id: string;
    event_code: string;
    event_label: string;
    actor_name: string;
    module_label: string;
    description: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    created_at: string;
}

const props = defineProps<{
    visible: boolean;
    title?: string;
    auditableType: string;
    auditableId: string;
    module?: string;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
}>();

const logs = ref<LogRow[]>([]);
const loading = ref(false);
const error = ref(false);

const EVENT_COLOR: Record<string, string> = {
    created: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    updated:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    validated:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    deleted: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    paid: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    submitted:
        'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    exported:
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300',
    printed:
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300',
    auto_generated:
        'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
    auto_recalculated:
        'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
    frais_added:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    frais_deleted:
        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    payment_cancelled:
        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    status_changed:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    encaissement_added:
        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    encaissement_deleted:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    stock_adjusted:
        'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
};

function badgeClass(code: string): string {
    return (
        EVENT_COLOR[code] ??
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300'
    );
}

async function fetchLogs() {
    if (!props.auditableType || !props.auditableId) return;
    loading.value = true;
    error.value = false;
    logs.value = [];
    try {
        const params = new URLSearchParams({
            auditable_type: props.auditableType,
            auditable_id: props.auditableId,
        });
        if (props.module) params.set('module', props.module);
        const res = await fetch(
            `/comptabilite/historique/entite?${params.toString()}`,
            { headers: { Accept: 'application/json' } },
        );
        if (!res.ok) throw new Error('HTTP ' + res.status);
        logs.value = await res.json();
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.visible,
    (val) => {
        if (val) fetchLogs();
    },
);
</script>

<template>
    <PvSidebar
        :visible="visible"
        position="right"
        :style="{ width: '480px' }"
        @update:visible="emit('update:visible', $event)"
    >
        <template #header>
            <div class="flex items-center gap-2">
                <span class="font-semibold">{{ title ?? 'Historique' }}</span>
            </div>
        </template>

        <div class="flex h-full flex-col">
            <div v-if="loading" class="flex flex-1 items-center justify-center">
                <div
                    class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                />
            </div>

            <div
                v-else-if="error"
                class="flex flex-1 items-center justify-center text-sm text-destructive"
            >
                Impossible de charger l'historique.
            </div>

            <div
                v-else-if="logs.length === 0"
                class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
            >
                Aucune action enregistrée.
            </div>

            <div v-else class="space-y-0 divide-y overflow-y-auto">
                <div v-for="log in logs" :key="log.id" class="px-1 py-4">
                    <div class="flex items-start gap-3">
                        <div class="flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="badgeClass(log.event_code)"
                                >
                                    {{ log.event_label }}
                                </span>
                                <span class="text-xs text-muted-foreground">{{
                                    log.created_at
                                }}</span>
                            </div>
                            <p
                                v-if="log.description"
                                class="text-sm font-medium"
                            >
                                {{ log.description }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ log.actor_name }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PvSidebar>
</template>
