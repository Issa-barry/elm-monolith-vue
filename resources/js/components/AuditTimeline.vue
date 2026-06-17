<script setup lang="ts">
import { onMounted, ref } from 'vue';

interface AuditEntry {
    id: string;
    event_code: string;
    event_label: string;
    actor_name: string;
    description: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    created_at: string;
}

const props = defineProps<{
    auditableType: string;
    auditableId: string;
    module?: string;
}>();

const logs = ref<AuditEntry[]>([]);
const loading = ref(true);
const error = ref(false);

async function fetchLogs() {
    loading.value = true;
    error.value = false;
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
        if (!res.ok) throw new Error();
        logs.value = await res.json();
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(fetchLogs);

const EVENT_COLOR: Record<string, string> = {
    created: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    updated:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    deleted: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    validated:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    submitted:
        'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    payment_cancelled:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    exported:
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300',
    printed:
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300',
    auto_generated:
        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    auto_recalculated:
        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    status_changed:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    frais_added:
        'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
    frais_deleted:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
};

function badgeClass(code: string) {
    return (
        EVENT_COLOR[code] ??
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300'
    );
}

function hasChanges(log: AuditEntry) {
    return !!(log.old_values || log.new_values);
}

const expandedId = ref<string | null>(null);

function toggleExpand(id: string) {
    expandedId.value = expandedId.value === id ? null : id;
}
</script>

<template>
    <div>
        <!-- Loading -->
        <div
            v-if="loading"
            class="flex items-center justify-center py-12 text-muted-foreground"
        >
            <svg
                class="mr-2 h-4 w-4 animate-spin"
                viewBox="0 0 24 24"
                fill="none"
            >
                <circle
                    class="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    stroke-width="4"
                />
                <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8v8z"
                />
            </svg>
            Chargement…
        </div>

        <!-- Error -->
        <div
            v-else-if="error"
            class="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
        >
            Impossible de charger l'historique.
        </div>

        <!-- Empty -->
        <div
            v-else-if="logs.length === 0"
            class="flex flex-col items-center gap-2 py-12 text-muted-foreground"
        >
            <svg
                class="h-8 w-8 opacity-30"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.5"
                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
            <p class="text-sm">Aucune action enregistrée.</p>
        </div>

        <!-- Timeline -->
        <ol v-else class="relative space-y-0 border-l border-border/50 pl-6">
            <li
                v-for="log in logs"
                :key="log.id"
                class="relative pb-6 last:pb-0"
            >
                <!-- Dot -->
                <span
                    class="absolute -left-[25px] flex h-3 w-3 items-center justify-center rounded-full border-2 border-background bg-border"
                />

                <div class="space-y-1">
                    <!-- Row principale -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="badgeClass(log.event_code)"
                        >
                            {{ log.event_label }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ log.created_at }}
                        </span>
                        <span class="text-xs font-medium text-foreground">
                            {{ log.actor_name }}
                        </span>
                    </div>

                    <!-- Description -->
                    <p
                        v-if="log.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ log.description }}
                    </p>

                    <!-- Avant / Après — toggle -->
                    <div v-if="hasChanges(log)">
                        <button
                            type="button"
                            class="mt-1 text-xs text-primary underline-offset-2 hover:underline"
                            @click="toggleExpand(log.id)"
                        >
                            {{
                                expandedId === log.id
                                    ? 'Masquer les détails'
                                    : 'Voir les détails'
                            }}
                        </button>

                        <div
                            v-if="expandedId === log.id"
                            class="mt-2 grid grid-cols-1 gap-3 rounded-lg bg-muted/40 p-3 sm:grid-cols-2"
                        >
                            <div v-if="log.old_values">
                                <p
                                    class="mb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Avant
                                </p>
                                <dl class="space-y-0.5 text-xs">
                                    <div
                                        v-for="(val, key) in log.old_values"
                                        :key="key"
                                        class="flex gap-2"
                                    >
                                        <dt
                                            class="min-w-[90px] font-medium text-muted-foreground"
                                        >
                                            {{ key }}
                                        </dt>
                                        <dd>{{ val ?? '—' }}</dd>
                                    </div>
                                </dl>
                            </div>
                            <div v-if="log.new_values">
                                <p
                                    class="mb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Après
                                </p>
                                <dl class="space-y-0.5 text-xs">
                                    <div
                                        v-for="(val, key) in log.new_values"
                                        :key="key"
                                        class="flex gap-2"
                                    >
                                        <dt
                                            class="min-w-[90px] font-medium text-muted-foreground"
                                        >
                                            {{ key }}
                                        </dt>
                                        <dd
                                            class="text-emerald-700 dark:text-emerald-400"
                                        >
                                            {{ val ?? '—' }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
