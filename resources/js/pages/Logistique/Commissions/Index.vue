<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronRight,
    HandCoins,
    PackageSearch,
    Search,
    Truck,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dropdown from 'primevue/dropdown';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface CommissionItem {
    id: number;
    transfert_id: number;
    transfert_reference: string | null;
    site_source_nom: string | null;
    site_destination_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    base_calcul_label: string;
    montant_total: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    nb_parts: number;
    created_at: string;
}

interface Kpis {
    total: number;
    montant_total: number;
    montant_verse: number;
    montant_restant: number;
    nb_en_attente: number;
    nb_partiellement: number;
    nb_versees: number;
}

interface StatutOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    commissions: CommissionItem[];
    kpis: Kpis;
    statuts: StatutOption[];
    filtre_statut: string | null;
    filtre_reference: string | null;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
];

// ── Filtres serveur ───────────────────────────────────────────────────────────

const statutFiltre  = ref(props.filtre_statut ?? null);
const referenceFiltre = ref(props.filtre_reference ?? '');

function appliquerFiltres() {
    router.get(
        '/logistique/commissions',
        {
            statut:    statutFiltre.value ?? undefined,
            reference: referenceFiltre.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

watch(statutFiltre, appliquerFiltres);

// ── Filtre local (recherche DataTable) ───────────────────────────────────────

const search  = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });

watch(search, (val) => { filters.value.global.value = val; });

// ── Mobile ────────────────────────────────────────────────────────────────────

const mobileSearch = ref('');
const mobileFiltrees = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.commissions;
    return props.commissions.filter(
        (c) =>
            (c.transfert_reference?.toLowerCase().includes(q)) ||
            (c.site_source_nom?.toLowerCase().includes(q)) ||
            (c.site_destination_nom?.toLowerCase().includes(q)) ||
            (c.vehicule_nom?.toLowerCase().includes(q)),
    );
});

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head title="Commissions — Logistique" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ── MOBILE ──────────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3">
                <Link href="/logistique/transferts" class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground">
                    <ChevronRight class="h-5 w-5 rotate-180" />
                </Link>
                <span class="text-base font-semibold">Commissions logistiques</span>
                <div class="w-8" />
            </div>
            <div class="border-b px-4 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Référence, site…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>
            <div class="divide-y">
                <Link
                    v-for="c in mobileFiltrees"
                    :key="c.id"
                    :href="`/logistique/commissions/${c.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 hover:bg-muted/10"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-semibold text-primary">{{ c.transfert_reference ?? '—' }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ c.site_source_nom ?? '—' }} → {{ c.site_destination_nom ?? '—' }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ c.vehicule_nom ?? '—' }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <StatusDot :label="c.statut_label" :dot-class="c.statut_dot_class" class="text-xs text-muted-foreground" />
                        <span class="text-xs font-semibold tabular-nums">{{ formatGNF(c.montant_total) }}</span>
                    </div>
                </Link>
            </div>
            <div v-if="mobileFiltrees.length === 0" class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                <HandCoins class="h-10 w-10 opacity-30" />
                <p class="text-sm">Aucune commission trouvée.</p>
            </div>
        </div>

        <!-- ── DESKTOP ─────────────────────────────────────────────────────── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Commissions logistiques</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.total }} commission{{ kpis.total !== 1 ? 's' : '' }}
                    </p>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total généré</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-foreground">{{ formatGNF(kpis.montant_total) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Versé</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(kpis.montant_verse) }}</p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="kpis.montant_restant > 0 ? 'border-amber-200 dark:border-amber-900' : ''"
                >
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Restant à verser</p>
                    <p class="mt-1 text-xl font-bold tabular-nums" :class="kpis.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'">
                        {{ formatGNF(kpis.montant_restant) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">En attente / Partielles</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-red-600 dark:text-red-400">
                        {{ kpis.nb_en_attente + kpis.nb_partiellement }}
                    </p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="commissions"
                    :paginator="commissions.length > 25"
                    :rows="25"
                    :global-filter-fields="['transfert_reference', 'site_source_nom', 'site_destination_nom', 'vehicule_nom', 'statut_label']"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <template #header>
                        <div class="flex flex-wrap items-center gap-3">
                            <IconField class="max-w-xs flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search class="h-4 w-4 text-muted-foreground" />
                                </InputIcon>
                                <InputText v-model="search" placeholder="Rechercher…" class="w-full text-sm" />
                            </IconField>
                            <Dropdown
                                :options="[{ value: null, label: 'Tous les statuts' }, ...statuts]"
                                option-label="label"
                                option-value="value"
                                :model-value="statutFiltre"
                                placeholder="Tous les statuts"
                                class="w-52 text-sm"
                                @change="(e) => (statutFiltre = e.value)"
                            />
                            <span class="text-xs text-muted-foreground">{{ commissions.length }} résultat{{ commissions.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Référence transfert -->
                    <Column field="transfert_reference" header="Référence" sortable style="min-width: 170px">
                        <template #body="{ data }">
                            <Link :href="`/logistique/commissions/${data.id}`" class="font-mono text-sm font-semibold tracking-wide text-primary hover:underline">
                                {{ data.transfert_reference ?? '—' }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Trajet -->
                    <Column header="Trajet" style="min-width: 200px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1 text-sm">
                                <span class="font-medium">{{ data.site_source_nom ?? '—' }}</span>
                                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <span class="font-medium">{{ data.site_destination_nom ?? '—' }}</span>
                            </div>
                        </template>
                    </Column>

                    <!-- Véhicule -->
                    <Column field="vehicule_nom" header="Véhicule" style="min-width: 130px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">
                                {{ data.vehicule_nom ?? '—' }}
                                <span v-if="data.immatriculation" class="ml-1 font-mono text-xs">({{ data.immatriculation }})</span>
                            </span>
                        </template>
                    </Column>

                    <!-- Base de calcul -->
                    <Column field="base_calcul_label" header="Base" sortable style="width: 130px">
                        <template #body="{ data }">
                            <span class="text-xs text-muted-foreground">{{ data.base_calcul_label }}</span>
                        </template>
                    </Column>

                    <!-- Montant total -->
                    <Column field="montant_total" header="Total" sortable style="width: 130px">
                        <template #body="{ data }">
                            <span class="font-semibold tabular-nums">{{ formatGNF(data.montant_total) }}</span>
                        </template>
                    </Column>

                    <!-- Versé -->
                    <Column field="montant_verse" header="Versé" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(data.montant_verse) }}</span>
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column field="montant_restant" header="Restant" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span
                                class="font-semibold tabular-nums"
                                :class="data.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                            >
                                {{ formatGNF(data.montant_restant) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut" header="Statut" sortable style="width: 160px">
                        <template #body="{ data }">
                            <StatusDot :label="data.statut_label" :dot-class="data.statut_dot_class" class="text-muted-foreground" />
                        </template>
                    </Column>

                    <!-- Action -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Link :href="`/logistique/commissions/${data.id}`">
                                <Button variant="ghost" size="icon" class="h-8 w-8">
                                    <PackageSearch class="h-4 w-4" />
                                </Button>
                            </Link>
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                            <Truck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucune commission logistique trouvée.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
