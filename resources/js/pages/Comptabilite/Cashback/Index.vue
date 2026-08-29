<script setup lang="ts">
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import CommissionIndexLayout from '@/components/commission/CommissionIndexLayout.vue';
import type { FilterField } from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import type { CommissionIndexSummary } from '@/types/commission';
import { Head } from '@inertiajs/vue3';
import { User } from 'lucide-vue-next';
import { computed } from 'vue';

interface Beneficiaire {
    client_id: string;
    client_nom: string;
    telephone: string | null;
    nb_transactions: number;
    total_genere: number;
    total_frais: number;
    total_net: number;
    total_verse: number;
    solde_restant: number;
    statut: string;
    statut_label: string;
}

const props = defineProps<{
    beneficiaires: Beneficiaire[];
    kpis: {
        nb_clients: number;
        total_genere: number;
        total_frais: number;
        total_net: number;
        total_verse: number;
        solde_total: number;
    };
    clients: { id: string; nom_complet: string }[];
    filters: {
        client_id: string;
        statut: string;
        date_debut: string;
        date_fin: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Cashback clients',
        href: '/backoffice/comptabilite/commissions/cashback',
    },
];

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: 'en_attente', label: 'En attente' },
            { value: 'valide', label: 'Validé' },
            { value: 'partiel', label: 'Partiel' },
            { value: 'verse', label: 'Versé' },
        ],
    },
    {
        key: 'client_id',
        label: 'Client',
        type: 'select',
        options: props.clients.map((client) => ({
            value: client.id,
            label: client.nom_complet,
        })),
    },
    {
        key: 'periode',
        label: 'Période',
        type: 'date-range',
        startKey: 'date_debut',
        endKey: 'date_fin',
    },
]);

const summary = computed<CommissionIndexSummary>(() => ({
    generated: props.kpis.total_genere,
    expenses: props.kpis.total_frais,
    netValidated: props.kpis.total_net,
    remaining: props.kpis.solde_total,
    paid: props.kpis.total_verse,
}));
</script>

<template>
    <Head title="Cashback clients — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <CommissionIndexLayout
            title="Cashback clients"
            :entity-count="kpis.nb_clients"
            entity-label="client"
            period-label="Toutes les périodes"
            filter-url="/backoffice/comptabilite/commissions/cashback"
            :filter-values="filters"
            :filter-fields="filterFields"
            hide-agence-selector
            :show-export="false"
            :summary="summary"
            table-title="Détail par client"
            :result-count="beneficiaires.length"
            empty-message="Aucun cashback trouvé."
        >
            <table class="w-full min-w-[1080px] text-sm">
                <thead>
                    <tr class="border-b bg-muted/50">
                        <th class="px-4 py-3 text-left font-semibold">Client</th>
                        <th class="px-4 py-3 text-center font-semibold">Gains</th>
                        <th class="px-4 py-3 text-right font-semibold">Généré</th>
                        <th class="px-4 py-3 text-right font-semibold">Dépenses</th>
                        <th class="px-4 py-3 text-right font-semibold">Net validé</th>
                        <th class="px-4 py-3 text-right font-semibold">Déjà payé</th>
                        <th class="px-4 py-3 text-right font-semibold">Reste à payer</th>
                        <th class="px-4 py-3 text-left font-semibold">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <ClickableTableRow
                        v-for="beneficiaire in beneficiaires"
                        :key="beneficiaire.client_id"
                        :href="`/backoffice/comptabilite/commissions/cashback/${beneficiaire.client_id}`"
                        :aria-label="`Voir le cashback de ${beneficiaire.client_nom}`"
                        class="even:bg-muted/20"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <User class="h-4 w-4 text-muted-foreground" />
                                <div>
                                    <p class="font-semibold">{{ beneficiaire.client_nom }}</p>
                                    <p v-if="beneficiaire.telephone" class="text-xs text-muted-foreground">
                                        {{ formatPhoneDisplay(beneficiaire.telephone) }}
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center tabular-nums">
                            {{ beneficiaire.nb_transactions }}
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">
                            {{ formatGNF(beneficiaire.total_genere) }}
                        </td>
                        <td class="px-4 py-3 text-right text-red-600 tabular-nums dark:text-red-400">
                            {{ beneficiaire.total_frais > 0 ? `-${formatGNF(beneficiaire.total_frais)}` : formatGNF(0) }}
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">
                            {{ formatGNF(beneficiaire.total_net) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatGNF(beneficiaire.total_verse) }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">
                            {{ formatGNF(beneficiaire.solde_restant) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusDot :status="beneficiaire.statut" :label="beneficiaire.statut_label" />
                        </td>
                    </ClickableTableRow>
                </tbody>
            </table>
        </CommissionIndexLayout>
    </AppLayout>
</template>
