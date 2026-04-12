<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, HandCoins, Truck } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface VehiculeRow {
    vehicule_id: number;
    nom: string;
    immatriculation: string | null;
    pending: number;
    available: number;
    paid: number;
    nb_transferts: number;
}

interface Kpis {
    nb_vehicules: number;
    total_pending: number;
    total_available: number;
    total_paid: number;
}

interface SelectOption {
    value: string | number | null;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    vehicules: VehiculeRow[];
    kpis: Kpis;
    vehicule_options: SelectOption[];
    filtre_vehicule: number | null;
    filtre_statut: string | null;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────

const vehiculeFiltre = ref<number | null>(props.filtre_vehicule ?? null);
const statutFiltre = ref<string | null>(props.filtre_statut ?? null);

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
            vehicule_id: vehiculeFiltre.value ?? undefined,
            statut: statutFiltre.value ?? undefined,
        },
        { preserveState: true, replace: true },
    );
}

watch([vehiculeFiltre, statutFiltre], appliquerFiltres);

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head title="Commissions — par véhicule" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── En-tête ───────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commissions logistiques
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_vehicules }} véhicule{{
                            kpis.nb_vehicules !== 1 ? 's' : ''
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
                <Dropdown
                    :options="[
                        { value: null, label: 'Tous les véhicules' },
                        ...vehicule_options,
                    ]"
                    option-label="label"
                    option-value="value"
                    :model-value="vehiculeFiltre"
                    placeholder="Tous les véhicules"
                    class="w-64 text-sm"
                    @change="(e) => (vehiculeFiltre = e.value)"
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
                    >{{ vehicules.length }} résultat{{
                        vehicules.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- ── Tableau véhicules ──────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table v-if="vehicules.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Véhicule
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
                            <th
                                class="px-4 py-3 text-center font-medium text-muted-foreground"
                            >
                                Transferts
                            </th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="v in vehicules"
                            :key="v.vehicule_id"
                            class="transition-colors hover:bg-muted/10"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Truck
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                    <div>
                                        <p class="font-medium">{{ v.nom }}</p>
                                        <p
                                            v-if="v.immatriculation"
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{ v.immatriculation }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td
                                class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ formatGNF(v.pending) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="
                                    v.available > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatGNF(v.available) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                {{ formatGNF(v.paid) }}
                            </td>
                            <td
                                class="px-4 py-3 text-center text-muted-foreground tabular-nums"
                            >
                                {{ v.nb_transferts }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="`/logistique/commissions/vehicules/${v.vehicule_id}`"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="gap-1"
                                    >
                                        Détail
                                        <ChevronRight class="h-3.5 w-3.5" />
                                    </Button>
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
