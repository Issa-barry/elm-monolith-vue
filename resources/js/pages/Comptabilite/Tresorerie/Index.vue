<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { formatGNF } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

interface Row {
    site_id: string | null;
    site_nom: string;
    livreurs_p1: number;
    livreurs_p2: number;
    proprietaires: number;
    salaires: number;
    total: number;
}

interface Totaux {
    livreurs_p1: number;
    livreurs_p2: number;
    proprietaires: number;
    salaires: number;
    total: number;
}

const props = defineProps<{
    rows: Row[];
    total_general: Totaux;
    filters: { annee: string; mois: string };
    is_admin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Besoin de trésorerie', href: '#' },
];

const anneeCourante = new Date().getFullYear();
const anneeOptions = Array.from({ length: 4 }, (_, i) => {
    const annee = anneeCourante + 1 - i;
    return { value: String(annee), label: String(annee) };
});

const moisOptions = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
].map((label, i) => ({ value: String(i + 1), label }));

const filterFields: FilterField[] = [
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        inline: true,
        options: anneeOptions,
    },
    {
        key: 'mois',
        label: 'Mois',
        type: 'select',
        inline: true,
        options: moisOptions,
    },
];

const moisLabel = computed(
    () =>
        moisOptions.find((m) => m.value === props.filters.mois)?.label ??
        props.filters.mois,
);

function detailHref(row: Row): string {
    const site = row.site_id ?? 'sans-agence';
    return `/backoffice/comptabilite/tresorerie/${site}?annee=${props.filters.annee}&mois=${props.filters.mois}`;
}

function goToDetail(row: Row) {
    router.visit(detailHref(row));
}
</script>

<template>
    <Head title="Besoin de trésorerie" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    Besoin de trésorerie
                </h1>
                <p class="text-sm text-muted-foreground">
                    Prévision — montant que le siège doit prévoir pour
                    {{ moisLabel }} {{ filters.annee }}, par agence, pour
                    couvrir les commissions livreurs (P1/P2), les commissions
                    propriétaires et les salaires. Ne reflète pas encore un
                    transfert de fonds réel — voir
                    <Link
                        href="/backoffice/comptabilite/journal"
                        class="underline underline-offset-2"
                        >le journal financier</Link
                    >
                    pour les mouvements déjà enregistrés.
                </p>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie"
                :values="filters"
                :fields="filterFields"
                :result-count="rows.length"
                hide-agence-selector
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Livreurs P1
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Livreurs P2
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Propriétaires
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Salaires
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                Total à envoyer
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rows"
                            :key="row.site_id ?? 'sans-agence'"
                            class="cursor-pointer hover:bg-muted/30"
                            @click="goToDetail(row)"
                        >
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                    @click.stop
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.livreurs_p1) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.livreurs_p2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.proprietaires) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.salaires) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(row.total) }}
                            </td>
                        </tr>

                        <tr v-if="rows.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun besoin de trésorerie pour cette période.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length > 0">
                        <tr class="border-t bg-muted/30 font-semibold">
                            <td class="px-4 py-3">Total général</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.livreurs_p1) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.livreurs_p2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.proprietaires) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.salaires) }}
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
