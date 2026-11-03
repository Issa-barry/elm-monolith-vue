<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { UserRound } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import type { ParrainListe } from './partials/types';

defineProps<{
    parrains: ParrainListe[];
    filters: Record<string, unknown>;
    peut_voir_commissions: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Parrains', href: '/backoffice/parrains' },
];

const { onRowClick, bodyRowPt } = useClickableTableRow<ParrainListe>(
    (parrain) => `/backoffice/parrains/${parrain.id}`,
);

const filterFields: FilterField[] = [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: [
            { value: 'actif', label: 'Actifs' },
            { value: 'inactif', label: 'Inactifs' },
        ],
    },
    {
        key: 'compte',
        label: 'Compte',
        type: 'select',
        inline: true,
        options: [
            { value: 'rattache', label: 'Rattaché' },
            { value: 'non_rattache', label: 'Non rattaché' },
        ],
    },
    {
        key: 'search',
        label: 'Rechercher',
        type: 'text',
        inline: true,
        placeholder: 'Nom, téléphone, e-mail...',
    },
];

function telephone(parrain: ParrainListe): string {
    return (
        formatPhoneDisplay(parrain.telephone, parrain.code_phone_pays) ?? '—'
    );
}

function nomCompte(parrain: ParrainListe): string {
    return parrain.compte?.nom ?? 'Nom non renseigné';
}

function libelleVehicules(nombre: number): string {
    return `${nombre} ${nombre > 1 ? 'véhicules' : 'véhicule'}`;
}
</script>

<template>
    <Head title="Parrains" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Parrains</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ parrains.length }} parrain{{
                        parrains.length !== 1 ? 's' : ''
                    }}. Un parrain s'ajoute depuis la fiche de son véhicule.
                </p>
            </div>

            <DataFilters
                url="/backoffice/parrains"
                :values="filters"
                :fields="filterFields"
                :result-count="parrains.length"
            />

            <!-- ── Mobile ───────────────────────────────────────────────── -->
            <div class="divide-y rounded-xl border bg-card sm:hidden">
                <Link
                    v-for="p in parrains"
                    :key="p.id"
                    :href="`/backoffice/parrains/${p.id}`"
                    class="block space-y-1 px-4 py-3.5 active:bg-muted/40"
                    data-testid="parrain-carte"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate text-sm font-medium">{{
                            p.nom_complet || 'Parrain sans nom'
                        }}</span>
                        <StatusDot
                            class="shrink-0 text-xs text-muted-foreground"
                            :status="p.is_active ? 'actif' : 'inactif'"
                            :label="p.is_active ? 'Actif' : 'Inactif'"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ telephone(p) }} ·
                        {{ libelleVehicules(p.vehicules_count) }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{
                            p.compte
                                ? `Compte : ${nomCompte(p)}`
                                : 'Aucun compte rattaché'
                        }}
                    </p>
                    <p
                        v-if="p.reste_a_payer"
                        class="text-xs font-medium tabular-nums"
                    >
                        Reste à payer : {{ formatGNF(p.reste_a_payer) }}
                    </p>
                </Link>
                <p
                    v-if="parrains.length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Aucun parrain trouvé.
                </p>
            </div>

            <!-- ── Desktop ──────────────────────────────────────────────── -->
            <div
                class="hidden overflow-hidden rounded-xl border bg-card sm:block"
            >
                <DataTable
                    :value="parrains"
                    :paginator="parrains.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
                >
                    <Column
                        field="nom_complet"
                        header="Parrain"
                        sortable
                        style="min-width: 240px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/backoffice/parrains/${data.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ data.nom_complet || 'Parrain sans nom' }}
                            </Link>
                            <div
                                v-if="data.email"
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.email }}
                            </div>
                        </template>
                    </Column>

                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 190px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{ telephone(data) }}</span
                            >
                        </template>
                    </Column>

                    <Column
                        field="vehicules_count"
                        header="Véhicules"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{ data.vehicules_count }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Compte" style="min-width: 200px">
                        <template #body="{ data }">
                            <span v-if="data.compte">{{
                                nomCompte(data)
                            }}</span>
                            <span v-else class="text-muted-foreground"
                                >Non rattaché</span
                            >
                        </template>
                    </Column>

                    <Column
                        v-if="peut_voir_commissions"
                        field="reste_a_payer"
                        header="Reste à payer"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span class="whitespace-nowrap tabular-nums">
                                {{ formatGNF(data.reste_a_payer) }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="is_active"
                        header="Statut"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                class="text-muted-foreground"
                                :status="data.is_active ? 'actif' : 'inactif'"
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                            />
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <UserRound class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun parrain trouvé.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
