<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRightLeft, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

// `en_cours_versement` : versements de caisses dédiées envoyés mais pas encore reçus. Déjà sortis de
// tous les soldes (transit au grand livre), donc jamais inclus dans `total` ni `par_type` — simple
// information de suivi pour expliquer un total qui baisse à l'envoi.
interface Row {
    site_id: string;
    site_nom: string;
    par_type: Record<string, number>;
    total: number;
    en_cours_versement: number;
    versements_en_cours: number;
}

interface TotalGeneral {
    par_type: Record<string, number>;
    total: number;
    en_cours_versement: number;
    versements_en_cours: number;
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

            <Alert
                v-if="total_general.en_cours_versement > 0"
                data-testid="situation-en-cours-versement"
            >
                <ArrowRightLeft class="text-blue-500" />
                <AlertTitle>
                    {{ formatGNF(total_general.en_cours_versement) }} en cours
                    de versement
                </AlertTitle>
                <AlertDescription>
                    {{
                        total_general.versements_en_cours > 1
                            ? `${total_general.versements_en_cours} versements envoyés par des caisses d'agents attendent la confirmation de la caisse de l'agence.`
                            : "1 versement envoyé par une caisse d'agent attend la confirmation de la caisse de l'agence."
                    }}
                    Ce montant n'est plus dans les soldes ci-dessus et n'est pas
                    encore crédité : il l'est dès la réception confirmée.
                </AlertDescription>
            </Alert>

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
                                <div
                                    v-if="row.en_cours_versement > 0"
                                    class="mt-0.5 text-xs font-medium whitespace-nowrap text-blue-600 dark:text-blue-400"
                                    data-testid="situation-site-en-cours"
                                >
                                    En cours de versement :
                                    {{ formatGNF(row.en_cours_versement) }}
                                </div>
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
                                {{
                                    formatGNF(
                                        total_general.par_type[t.value] ?? 0,
                                    )
                                }}
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
