<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, FileText } from 'lucide-vue-next';
import { computed } from 'vue';

interface Facture {
    id: string;
    reference: string;
    numero_facture_fournisseur: string | null;
    sans_justificatif: boolean;
    date_facture: string;
    fournisseur_nom: string | null;
    site_nom: string | null;
    commande_reference: string | null;
    montant_ttc: number;
    reste_du: number;
    statut: string;
    statut_label: string;
    comptabilite: { statut: string; label: string };
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    factures: {
        data: Facture[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        last_page: number;
    };
    dette_totale: number;
    filters: Record<string, unknown>;
    statuts: Option[];
    fournisseurs: Option[];
    sites: { id: string; nom: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Achats', href: '/backoffice/achats' },
    { title: 'Factures d’achat', href: '/backoffice/achats/factures' },
];

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: [{ value: '', label: 'Tous les statuts' }, ...props.statuts],
    },
    {
        key: 'fournisseur_id',
        label: 'Fournisseur',
        type: 'select',
        inline: true,
        searchable: true,
        options: [
            { value: '', label: 'Tous les fournisseurs' },
            ...props.fournisseurs,
        ],
    },
    {
        key: 'numero',
        label: 'Numéro',
        type: 'text',
        inline: true,
        placeholder: 'FAF-… ou n° fournisseur',
    },
]);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function paginationLabel(label: string): string {
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <Head title="Factures d’achat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Factures d’achat
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Une facture se saisit depuis la fiche d'un bon de
                        commande validé et réceptionné.
                    </p>
                </div>
                <div class="rounded-xl border bg-card px-4 py-2 text-right">
                    <p class="text-xs text-muted-foreground">
                        Dette fournisseurs (factures validées)
                    </p>
                    <p class="text-lg font-bold tabular-nums">
                        {{ formatGNF(dette_totale) }}
                    </p>
                </div>
            </div>

            <DataFilters
                url="/backoffice/achats/factures"
                :values="filters"
                :sites="sites"
                :result-count="factures.total"
                :fields="filterFields"
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Fournisseur</th>
                            <th class="px-4 py-3 font-medium">Bon</th>
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                TTC
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Reste dû
                            </th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 font-medium">Comptabilité</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="f in factures.data"
                            :key="f.id"
                            class="cursor-pointer hover:bg-muted/20"
                            @click="
                                router.visit(
                                    `/backoffice/achats/factures/${f.id}`,
                                )
                            "
                        >
                            <td class="px-4 py-3">
                                <span class="font-mono font-semibold">{{
                                    f.reference
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        f.sans_justificatif
                                            ? 'Sans justificatif'
                                            : f.numero_facture_fournisseur
                                              ? `N° ${f.numero_facture_fournisseur}`
                                              : 'Sans numéro'
                                    }}</span
                                >
                            </td>
                            <td
                                class="px-4 py-3 text-muted-foreground tabular-nums"
                            >
                                {{ f.date_facture }}
                            </td>
                            <td class="px-4 py-3">
                                {{ f.fournisseur_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ f.commande_reference ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ f.site_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(f.montant_ttc) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(f.reste_du) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="f.statut"
                                    :label="f.statut_label"
                                    class="text-muted-foreground"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="f.comptabilite.statut"
                                    :label="f.comptabilite.label"
                                    class="text-muted-foreground"
                                />
                            </td>
                        </tr>
                        <tr v-if="factures.data.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-16 text-center text-muted-foreground"
                            >
                                <FileText
                                    class="mx-auto mb-3 h-10 w-10 opacity-30"
                                />
                                Aucune facture d’achat.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="factures.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
            >
                <template v-for="link in factures.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm hover:bg-muted"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="link.label.includes('&laquo')"
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="link.label.includes('&raquo')"
                            class="h-4 w-4"
                        />
                        <span v-else>{{ paginationLabel(link.label) }}</span>
                    </Link>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
