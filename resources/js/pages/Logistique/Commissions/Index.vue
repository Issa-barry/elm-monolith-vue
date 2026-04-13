<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, HandCoins, User } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface LivreurRow {
    livreur_id: number;
    nom: string;
    pending: number;
    available: number;
    paid: number;
}

interface Kpis {
    nb_livreurs: number;
    total_pending: number;
    total_available: number;
    total_paid: number;
}

interface SelectOption {
    value: string | null;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    livreurs: LivreurRow[];
    kpis: Kpis;
    search: string;
    filtre_statut: string;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────

const searchVal = ref(props.search ?? '');
const statutFiltre = ref<string | null>(props.filtre_statut || null);

const STATUT_OPTIONS: SelectOption[] = [
    { value: null, label: 'Tous les statuts' },
    { value: 'available', label: 'Disponible à payer' },
    { value: 'pending', label: 'En attente de déblocage' },
    { value: 'paid', label: 'Entièrement versé' },
];

function appliquerFiltres() {
    router.get(
        '/logistique/commissions',
        {
            search: searchVal.value || undefined,
            statut: statutFiltre.value ?? undefined,
        },
        { preserveState: true, replace: true },
    );
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(appliquerFiltres, 300);
});
watch(statutFiltre, appliquerFiltres);

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head title="Commissions logistiques" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── En-tête ───────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commissions logistiques
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                        avec commissions
                    </p>
                </div>
            </div>

            <!-- ── KPIs ──────────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        En attente de déblocage
                    </p>
                    <p
                        class="mt-1 text-xl font-bold text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ formatGNF(kpis.total_pending) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="
                        kpis.total_available > 0
                            ? 'border-amber-200 dark:border-amber-900'
                            : ''
                    "
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Disponible à payer
                    </p>
                    <p
                        class="mt-1 text-xl font-bold tabular-nums"
                        :class="
                            kpis.total_available > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ formatGNF(kpis.total_available) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Versé (total)
                    </p>
                    <p
                        class="mt-1 text-xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ formatGNF(kpis.total_paid) }}
                    </p>
                </div>
            </div>

            <!-- ── Filtres ────────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <InputText
                    v-model="searchVal"
                    placeholder="Rechercher un livreur…"
                    class="w-64 text-sm"
                />
                <Dropdown
                    :options="STATUT_OPTIONS"
                    option-label="label"
                    option-value="value"
                    :model-value="statutFiltre"
                    placeholder="Tous les statuts"
                    class="w-52 text-sm"
                    @change="(e) => (statutFiltre = e.value)"
                />
                <span class="text-xs text-muted-foreground"
                    >{{ livreurs.length }} résultat{{
                        livreurs.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- ── Tableau livreurs ──────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table v-if="livreurs.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Livreur
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                En attente
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                Disponible
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                Versé
                            </th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in livreurs"
                            :key="l.livreur_id"
                            class="transition-colors hover:bg-muted/10"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <User
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                    <span class="font-medium">{{ l.nom }}</span>
                                </div>
                            </td>
                            <td
                                class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ formatGNF(l.pending) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="
                                    l.available > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatGNF(l.available) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                {{ formatGNF(l.paid) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="`/logistique/commissions/livreurs/${l.livreur_id}`"
                                >
                                    <button
                                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    >
                                        Détail
                                        <ChevronRight class="h-3.5 w-3.5" />
                                    </button>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune commission trouvée pour ce filtre.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
