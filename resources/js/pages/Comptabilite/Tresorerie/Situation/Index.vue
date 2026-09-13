<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

interface Row {
    site_id: string;
    site_nom: string;
    par_type: Record<string, number>;
    total: number;
}

interface TotalGeneral {
    par_type: Record<string, number>;
    total: number;
}

const props = defineProps<{
    rows: Row[];
    total_general: TotalGeneral;
    type_options: { value: string; label: string }[];
    filters: { date: string; site_ids: string[] };
    sites: { value: string; label: string }[];
    is_admin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Situation de trésorerie', href: '#' },
];

const filterFields: FilterField[] = [
    { key: 'date', label: 'Date de situation', type: 'date' },
];

// DataFilters attend { id, nom } (convention Site), pas { value, label }.
const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);

function detailHref(row: Row): string {
    return `/backoffice/comptabilite/tresorerie/situation/${row.site_id}?date=${props.filters.date}`;
}
</script>

<template>
    <Head title="Situation de trésorerie" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    Situation de trésorerie
                </h1>
                <p class="text-sm text-muted-foreground">
                    Solde actuel de chaque support de trésorerie, par agence —
                    calculé depuis le grand livre comptable.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Trésorerie totale
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.total) }}
                    </p>
                </div>
                <div
                    v-for="t in type_options"
                    :key="t.value"
                    class="rounded-xl border bg-card p-4"
                >
                    <p class="text-sm text-muted-foreground">{{ t.label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.par_type[t.value] ?? 0) }}
                    </p>
                </div>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie/situation"
                :values="filters"
                :fields="filterFields"
                :sites="sitesPourFiltre"
                :result-count="rows.length"
                hide-result-count
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th
                                v-for="t in type_options"
                                :key="t.value"
                                class="px-4 py-3 text-right font-medium"
                            >
                                {{ t.label }}
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                Total disponible
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rows"
                            :key="row.site_id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td
                                v-for="t in type_options"
                                :key="t.value"
                                class="px-4 py-3 text-right tabular-nums"
                            >
                                {{ formatGNF(row.par_type[t.value] ?? 0) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(row.total) }}
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="2 + type_options.length"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucune agence.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length > 0">
                        <tr class="border-t bg-muted/20 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td
                                v-for="t in type_options"
                                :key="t.value"
                                class="px-4 py-3 text-right tabular-nums"
                            >
                                {{ formatGNF(total_general.par_type[t.value] ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
