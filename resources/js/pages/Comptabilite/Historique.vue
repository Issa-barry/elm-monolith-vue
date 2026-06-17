<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Eye, History, Search } from 'lucide-vue-next';
import PvDialog from 'primevue/dialog';
import PvDropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { ref, watch } from 'vue';

interface LogRow {
    id: string;
    event_code: string;
    event_label: string;
    actor_name: string;
    auditable_type: string;
    auditable_id: string | null;
    module: string | null;
    module_label: string;
    description: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    created_at: string;
}

interface SelectOption {
    value: string | null;
    label: string;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    logs: Paginator<LogRow>;
    filters: {
        dateDebut: string;
        dateFin: string;
        module: string;
        eventCode: string;
        actorId: string;
        siteId: string;
        search: string;
    };
    acteurs: SelectOption[];
    event_codes: SelectOption[];
    modules: SelectOption[];
    sites: SelectOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Historique', href: '/comptabilite/historique' },
];

const dateDebut = ref(props.filters.dateDebut ?? '');
const dateFin = ref(props.filters.dateFin ?? '');
const moduleFiltre = ref<string | null>(props.filters.module || null);
const eventCodeFiltre = ref<string | null>(props.filters.eventCode || null);
const actorFiltre = ref<string | null>(props.filters.actorId || null);
const siteFiltre = ref<string | null>(props.filters.siteId || null);
const searchQuery = ref(props.filters.search ?? '');

const ALL_MODULES = [
    { value: null, label: 'Tous les modules' },
    ...props.modules,
];
const ALL_EVENTS = [
    { value: null, label: 'Toutes les actions' },
    ...props.event_codes,
];
const ALL_ACTEURS = [
    { value: null, label: 'Tous les utilisateurs' },
    ...props.acteurs,
];
const ALL_SITES = [{ value: null, label: 'Tous les sites' }, ...props.sites];

function appliquerFiltres() {
    router.get(
        '/comptabilite/historique',
        {
            date_debut: dateDebut.value || undefined,
            date_fin: dateFin.value || undefined,
            module: moduleFiltre.value ?? undefined,
            event_code: eventCodeFiltre.value ?? undefined,
            actor_id: actorFiltre.value ?? undefined,
            site_id: siteFiltre.value ?? undefined,
            search: searchQuery.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

watch(moduleFiltre, appliquerFiltres);
watch(eventCodeFiltre, appliquerFiltres);
watch(actorFiltre, appliquerFiltres);
watch(siteFiltre, appliquerFiltres);

let debounceTimeout: ReturnType<typeof setTimeout> | null = null;
watch([dateDebut, dateFin, searchQuery], () => {
    if (debounceTimeout) clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(appliquerFiltres, 400);
});

const selectedLog = ref<LogRow | null>(null);
const showDetail = ref(false);

function openDetail(log: LogRow) {
    selectedLog.value = log;
    showDetail.value = true;
}

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
    encaissement_added:
        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    encaissement_deleted:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    stock_adjusted:
        'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
};

function eventBadgeClass(code: string): string {
    return (
        EVENT_COLOR[code] ??
        'bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300'
    );
}

function hasChanges(log: LogRow): boolean {
    return !!(log.old_values || log.new_values || log.meta);
}

function formatJson(val: unknown): string {
    if (!val) return '—';
    try {
        return JSON.stringify(val, null, 2);
    } catch {
        return String(val);
    }
}
</script>

<template>
    <Head title="Historique des actions — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Historique des actions
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ logs.total }} entrée{{ logs.total !== 1 ? 's' : '' }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <FilterBar>
                <PvDropdown
                    :options="ALL_MODULES"
                    option-label="label"
                    option-value="value"
                    :model-value="moduleFiltre"
                    placeholder="Tous les modules"
                    class="w-56 text-sm"
                    @change="(e) => (moduleFiltre = e.value)"
                />
                <PvDropdown
                    :options="ALL_EVENTS"
                    option-label="label"
                    option-value="value"
                    :model-value="eventCodeFiltre"
                    placeholder="Toutes les actions"
                    class="w-52 text-sm"
                    @change="(e) => (eventCodeFiltre = e.value)"
                />
                <PvDropdown
                    :options="ALL_ACTEURS"
                    option-label="label"
                    option-value="value"
                    :model-value="actorFiltre"
                    placeholder="Tous les utilisateurs"
                    class="w-52 text-sm"
                    @change="(e) => (actorFiltre = e.value)"
                />
                <PvDropdown
                    :options="ALL_SITES"
                    option-label="label"
                    option-value="value"
                    :model-value="siteFiltre"
                    placeholder="Tous les sites"
                    class="w-44 text-sm"
                    @change="(e) => (siteFiltre = e.value)"
                />
                <InputText
                    v-model="dateDebut"
                    type="date"
                    class="w-40 text-sm"
                    placeholder="Du"
                />
                <InputText
                    v-model="dateFin"
                    type="date"
                    class="w-40 text-sm"
                    placeholder="Au"
                />
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <InputText
                        v-model="searchQuery"
                        type="text"
                        class="w-52 pl-8 text-sm"
                        placeholder="Recherche libre…"
                    />
                </div>
            </FilterBar>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table v-if="logs.data.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Utilisateur
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Module
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Action
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Entité
                            </th>
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Description
                            </th>
                            <th class="w-10 px-4 py-3.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="transition-colors hover:bg-muted/10"
                        >
                            <td
                                class="px-5 py-3.5 text-xs text-muted-foreground"
                            >
                                {{ log.created_at }}
                            </td>
                            <td class="px-5 py-3.5 font-medium">
                                {{ log.actor_name }}
                            </td>
                            <td class="px-5 py-3.5 text-sm">
                                {{ log.module_label }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="eventBadgeClass(log.event_code)"
                                >
                                    {{ log.event_label }}
                                </span>
                            </td>
                            <td
                                class="px-5 py-3.5 text-xs text-muted-foreground"
                            >
                                <span v-if="log.auditable_type">
                                    {{ log.auditable_type }}
                                    <span
                                        v-if="log.auditable_id"
                                        class="ml-1 font-mono"
                                        >#{{ log.auditable_id.slice(-8) }}</span
                                    >
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td
                                class="px-5 py-3.5 text-sm text-muted-foreground"
                            >
                                {{ log.description ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="hasChanges(log)"
                                    type="button"
                                    class="rounded p-1 hover:bg-muted/50"
                                    title="Voir les détails"
                                    @click="openDetail(log)"
                                >
                                    <Eye
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <History class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune action enregistrée pour ce filtre.
                    </p>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="logs.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    {{ logs.from }}–{{ logs.to }} sur {{ logs.total }}
                </p>
                <div class="flex items-center gap-1">
                    <a
                        v-for="link in logs.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="inline-flex h-8 items-center justify-center rounded px-3 text-sm transition-colors"
                        :class="[
                            link.active
                                ? 'bg-primary font-medium text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted/50',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                        v-html="
                            link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›')
                        "
                    />
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Détail Dialog -->
    <PvDialog
        v-model:visible="showDetail"
        modal
        header="Détail de l'action"
        :style="{ width: '640px' }"
        :draggable="false"
    >
        <template v-if="selectedLog">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Date
                        </p>
                        <p class="mt-1">{{ selectedLog.created_at }}</p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Utilisateur
                        </p>
                        <p class="mt-1">{{ selectedLog.actor_name }}</p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Module
                        </p>
                        <p class="mt-1">{{ selectedLog.module_label }}</p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Action
                        </p>
                        <p class="mt-1">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="eventBadgeClass(selectedLog.event_code)"
                            >
                                {{ selectedLog.event_label }}
                            </span>
                        </p>
                    </div>
                    <div v-if="selectedLog.description" class="col-span-2">
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Description
                        </p>
                        <p class="mt-1">{{ selectedLog.description }}</p>
                    </div>
                </div>

                <div
                    v-if="selectedLog.old_values || selectedLog.new_values"
                    class="space-y-3"
                >
                    <div v-if="selectedLog.old_values">
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                        >
                            Avant
                        </p>
                        <pre
                            class="max-h-48 overflow-auto rounded-lg bg-muted/50 p-3 text-xs"
                            >{{ formatJson(selectedLog.old_values) }}</pre
                        >
                    </div>
                    <div v-if="selectedLog.new_values">
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                        >
                            Après
                        </p>
                        <pre
                            class="max-h-48 overflow-auto rounded-lg bg-muted/50 p-3 text-xs"
                            >{{ formatJson(selectedLog.new_values) }}</pre
                        >
                    </div>
                </div>

                <div
                    v-if="
                        selectedLog.meta &&
                        Object.keys(selectedLog.meta).length > 0
                    "
                >
                    <p
                        class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                    >
                        Informations complémentaires
                    </p>
                    <div class="space-y-1 rounded-lg bg-muted/50 p-3 text-sm">
                        <div
                            v-for="(val, key) in selectedLog.meta"
                            :key="key"
                            class="flex gap-2"
                        >
                            <span
                                class="min-w-[120px] font-medium text-muted-foreground"
                                >{{ key }}</span
                            >
                            <span>{{ val }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </PvDialog>
</template>
