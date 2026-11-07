<script setup lang="ts">
/**
 * Liste « Entreprises » du référentiel Contacts (ADR 0060, lot 2) — lecture seule. Le serveur ne
 * renvoie que les entreprises et les fonctions visibles de l'utilisateur.
 */
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed } from 'vue';
import BadgesFonctions from '../partials/BadgesFonctions.vue';
import PaginationContacts from '../partials/PaginationContacts.vue';
import type {
    EntrepriseListe,
    OptionsFiltres,
    Pagination,
} from '../partials/types';

const props = defineProps<{
    entreprises: Pagination<EntrepriseListe>;
    filters: Record<string, unknown>;
    options: OptionsFiltres;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Entreprises', href: '/backoffice/contacts/entreprises' },
];

const lien = (e: EntrepriseListe) => `/backoffice/contacts/entreprises/${e.id}`;
const { onRowClick, bodyRowPt } = useClickableTableRow<EntrepriseListe>(lien);

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: [
            { value: 'actif', label: 'Actives' },
            { value: 'inactif', label: 'Inactives' },
        ],
    },
    {
        key: 'fonction',
        label: 'Fonction',
        type: 'select',
        inline: true,
        options: props.options.fonctions,
    },
    {
        key: 'recherche',
        label: 'Rechercher',
        type: 'text',
        inline: true,
        placeholder: 'Raison sociale, téléphone, NIF...',
    },
    {
        key: 'sous_categorie',
        label: 'Sous-catégorie',
        type: 'select',
        options: props.options.sous_categories,
    },
    {
        key: 'ville',
        label: 'Ville',
        type: 'text',
        placeholder: 'Ville',
    },
]);

function telephone(e: EntrepriseListe): string {
    return formatPhoneDisplay(e.telephone, e.code_phone_pays) ?? '—';
}
</script>

<template>
    <Head title="Entreprises" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Entreprises
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ entreprises.total }} entreprise{{
                        entreprises.total !== 1 ? 's' : ''
                    }}. Une seule fiche par entreprise, avec toutes ses
                    fonctions.
                </p>
            </div>

            <DataFilters
                url="/backoffice/contacts/entreprises"
                :values="filters"
                :fields="filterFields"
                :result-count="entreprises.total"
            />

            <!-- ── Mobile ───────────────────────────────────────────────── -->
            <div class="divide-y rounded-xl border bg-card sm:hidden">
                <Link
                    v-for="e in entreprises.data"
                    :key="e.id"
                    :href="lien(e)"
                    class="block space-y-1.5 px-4 py-3.5 active:bg-muted/40"
                    data-testid="entreprise-carte"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate text-sm font-medium">{{
                            e.raison_sociale
                        }}</span>
                        <StatusDot
                            class="shrink-0 text-xs text-muted-foreground"
                            :status="e.is_active ? 'actif' : 'inactif'"
                            :label="e.is_active ? 'Active' : 'Inactive'"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ telephone(e) }} · {{ e.ville || '—' }}
                    </p>
                    <BadgesFonctions :fonctions="e.fonctions" />
                </Link>
                <p
                    v-if="entreprises.data.length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Aucune entreprise trouvée.
                </p>
            </div>

            <!-- ── Desktop ──────────────────────────────────────────────── -->
            <div
                class="hidden overflow-hidden rounded-xl border bg-card sm:block"
            >
                <DataTable
                    :value="entreprises.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    table-class="w-full"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
                >
                    <Column header="Entreprise" style="min-width: 220px">
                        <template #body="{ data }">
                            <Link
                                :href="lien(data)"
                                class="font-medium hover:underline"
                                data-testid="entreprise-nom"
                            >
                                {{ data.raison_sociale }}
                            </Link>
                            <div
                                v-if="data.nom_commercial"
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.nom_commercial }}
                            </div>
                        </template>
                    </Column>
                    <Column header="Téléphone" style="width: 180px">
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{ telephone(data) }}</span
                            >
                        </template>
                    </Column>
                    <Column header="Ville" style="width: 140px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.ville || '—'
                            }}</span>
                        </template>
                    </Column>
                    <Column header="Fonctions" style="min-width: 200px">
                        <template #body="{ data }">
                            <BadgesFonctions :fonctions="data.fonctions" />
                        </template>
                    </Column>
                    <Column
                        header="Interlocuteur principal"
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <span
                                :class="
                                    data.interlocuteur_principal
                                        ? ''
                                        : 'text-muted-foreground'
                                "
                                >{{ data.interlocuteur_principal || '—' }}</span
                            >
                        </template>
                    </Column>
                    <Column header="Statut" style="width: 110px">
                        <template #body="{ data }">
                            <StatusDot
                                class="text-muted-foreground"
                                :status="data.is_active ? 'actif' : 'inactif'"
                                :label="data.is_active ? 'Active' : 'Inactive'"
                            />
                        </template>
                    </Column>
                    <template #empty>
                        <p
                            class="py-10 text-center text-sm text-muted-foreground"
                        >
                            Aucune entreprise trouvée.
                        </p>
                    </template>
                </DataTable>
            </div>

            <PaginationContacts :page="entreprises" />
        </div>
    </AppLayout>
</template>
